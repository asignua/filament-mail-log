<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Asignua\FilamentMailLog\Jobs\DeliverBounceAlert;
use Asignua\FilamentMailLog\Mail\BounceAlertMail;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells people about a hard bounce, a complaint or a newly suppressed address (`alerts` config, off by default).
 *
 * It runs inside the webhook request, so it must never break it: every failure is reported and swallowed (the event
 * is already stored, and a provider retry would only hit the idempotency check). Throttled twice: one alert per
 * address per window, and a ceiling of alerts per window; the alerts held back are counted and named by the next one.
 */
final class BounceAlerts
{
    public const string HARD_BOUNCE = 'hard_bounce';

    public const string COMPLAINT = 'complaint';

    public const string SUPPRESSION = 'suppression';

    private const string CACHE = 'filament-mail-log:alerts:';

    private const int HELD_NAMES = 20;

    /** @var (Closure(): iterable<Authenticatable>)|null */
    private static ?Closure $recipients = null;

    /**
     * RECOMMENDED for any site with more than a few hundred users. Replaces the gate lookup with your own list of
     * users (one team, one role, a query that selects the few admins): the gate has to be asked about each user, so
     * without this the alert job walks the users table (bounded by `alerts.scan_limit`). Return the users to notify.
     *
     * @param (Closure(): iterable<Authenticatable>)|null $callback
     */
    public static function recipientsUsing(?Closure $callback): void
    {
        self::$recipients = $callback;
    }

    /**
     * @param bool $stored          the event was new (not a provider redelivery)
     * @param bool $newlySuppressed the address was added to the suppression list by this event
     *
     * @return bool whether an alert went out
     */
    public function handle(DeliveryEvent $event, ?MailLog $log, bool $stored, bool $newlySuppressed): bool
    {
        try {
            if (!(bool) config('filament-mail-log.alerts.enabled', false)) {
                return false;
            }

            $kinds = $this->kinds($event, $stored && $log !== null, $newlySuppressed);

            // An alert about the alert mail itself would loop: it bounces, which alerts, which bounces ...
            if ($kinds === [] || $log?->type === BounceAlertMail::class) {
                return false;
            }

            $address = mb_strtolower((string) ($event->recipient ?? ($log !== null ? $log->recipient : '')));

            $held = $this->pass($address);

            if ($held === null) {
                return false;
            }

            [$title, $lines] = $this->text($event, $log, $kinds, $address, $held, $newlySuppressed);

            // Only the cheap cache work above runs in the webhook request; the user lookup and the mail are a job.
            $job = new DeliverBounceAlert($title, $lines, $log === null ? null : (int) $log->getKey());
            $queue = (array) config('filament-mail-log.alerts.queue', []);
            $connection = $queue['connection'] ?? null;
            $name = $queue['name'] ?? null;

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }

            if (is_string($name) && $name !== '') {
                $job->onQueue($name);
            }

            dispatch($job);

            return true;
        } catch (Throwable $e) {
            Log::warning('filament-mail-log: could not send the bounce alert: '.$e->getMessage());

            return false;
        }
    }

    /**
     * What the event is, among the configured triggers. Empty when the event is not one to alert about.
     *
     * @return list<string>
     */
    public function kinds(DeliveryEvent $event, bool $storedForLog, bool $newlySuppressed): array
    {
        $on = array_map('strval', (array) config('filament-mail-log.alerts.on', [self::HARD_BOUNCE, self::COMPLAINT]));
        $kinds = [];

        // An event nobody can match (mail of another application on the same account) is not ours to report, but
        // the address it kills is: only the suppression trigger can fire for it.
        if ($storedForLog) {
            if ($event->type === DeliveryEventType::Complained && in_array(self::COMPLAINT, $on, true)) {
                $kinds[] = self::COMPLAINT;
            }

            if ($event->type === DeliveryEventType::Bounced && $event->bounce === BounceType::Hard && in_array(self::HARD_BOUNCE, $on, true)) {
                $kinds[] = self::HARD_BOUNCE;
            }
        }

        if ($newlySuppressed && in_array(self::SUPPRESSION, $on, true)) {
            $kinds[] = self::SUPPRESSION;
        }

        return $kinds;
    }

    /**
     * The throttle. Returns what was held back since the last alert (null = this alert is throttled).
     *
     * A quiet address is checked first WITHOUT being consumed, so an address that the ceiling holds back is not muted
     * and does not use up the ceiling twice; it is marked only when its alert really goes out. The ceiling counts in
     * a window-numbered key (`count:<n>`): the key always gets its TTL at creation and is never reused, so a key that
     * expired between `add` and `increment` can only leak one dead bucket, never hold alerts forever.
     *
     * @return array{count: int, addresses: list<string>}|null
     */
    private function pass(string $address): ?array
    {
        $throttle = (array) config('filament-mail-log.alerts.throttle', []);
        $perAddress = max(0, (int) ($throttle['per_address_minutes'] ?? 60));
        $max = max(0, (int) ($throttle['max'] ?? 10));
        $window = max(1, (int) ($throttle['window_minutes'] ?? 60)) * 60;
        $bucket = intdiv(now()->getTimestamp(), $window);
        $addressKey = self::CACHE.'addr:'.sha1($address);

        if ($perAddress > 0 && $address !== '' && Cache::has($addressKey)) {
            return null;
        }

        if ($max > 0) {
            Cache::add(self::CACHE.'count:'.$bucket, 0, now()->addSeconds($window * 2));
            $count = Cache::increment(self::CACHE.'count:'.$bucket);

            if (is_int($count) && $count > $max) {
                $this->hold($address, $window);

                return null;
            }
        }

        if ($perAddress > 0 && $address !== '') {
            Cache::put($addressKey, 1, now()->addMinutes($perAddress));
        }

        return $this->takeHeld();
    }

    /**
     * A single entry with its own TTL on every write (`put`), so it can never outlive the alerts it is about.
     */
    private function hold(string $address, int $window): void
    {
        $held = (array) Cache::get(self::CACHE.'held', []);
        $addresses = array_values(array_filter((array) ($held['addresses'] ?? []), is_string(...)));

        if ($address !== '' && !in_array($address, $addresses, true) && count($addresses) < self::HELD_NAMES) {
            $addresses[] = $address;
        }

        Cache::put(self::CACHE.'held', ['count' => (int) ($held['count'] ?? 0) + 1, 'addresses' => $addresses], now()->addSeconds($window * 3));
    }

    /**
     * @return array{count: int, addresses: list<string>}
     */
    private function takeHeld(): array
    {
        $held = (array) Cache::pull(self::CACHE.'held', []);

        return [
            'count' => (int) ($held['count'] ?? 0),
            'addresses' => array_values(array_filter((array) ($held['addresses'] ?? []), is_string(...))),
        ];
    }

    /**
     * @param list<string>                               $kinds
     * @param array{count: int, addresses: list<string>} $held
     *
     * @return array{0: string, 1: list<string>}
     */
    private function text(DeliveryEvent $event, ?MailLog $log, array $kinds, string $address, array $held, bool $newlySuppressed): array
    {
        $t = static fn (string $key, array $replace = []): string => __('filament-mail-log::filament-mail-log.alerts.'.$key, $replace);

        $title = $t(in_array(self::COMPLAINT, $kinds, true) ? 'title_complaint' : 'title_bounce', ['address' => $address === '' ? '—' : $address]);

        $lines = [];

        if ($log?->subject !== null && $log->subject !== '') {
            $lines[] = $t('line_subject', ['subject' => $log->subject]);
        }

        $lines[] = $t('line_provider', ['provider' => $event->provider]);

        if (filled($event->detail)) {
            $lines[] = $t('line_detail', ['detail' => Str::limit((string) $event->detail, 300)]);
        }

        if ($newlySuppressed) {
            $lines[] = $t('line_suppressed');
        }

        if ($held['count'] > 0) {
            $lines[] = $t('line_held', ['count' => $held['count'], 'addresses' => $held['addresses'] === [] ? '—' : implode(', ', $held['addresses'])]);
        }

        return [$title, $lines];
    }

    /**
     * The delivery half, run by {@see DeliverBounceAlert}: database notifications and the mail.
     *
     * @param list<string> $lines
     */
    public function deliver(string $title, array $lines, ?int $mailLogId): void
    {
        $log = $mailLogId === null ? null : MailLog::query()->find($mailLogId);

        $this->notifyDatabase($title, $lines, $log);
        $this->notifyMail($title, implode("\n", $lines));
    }

    /**
     * @param list<string> $lines
     */
    private function notifyDatabase(string $title, array $lines, ?MailLog $log): void
    {
        if (!(bool) config('filament-mail-log.alerts.database', true)) {
            return;
        }

        $users = $this->users();

        if ($users === []) {
            return;
        }

        // The body is sanitized HTML: one line per <br> (each line escaped), a plain "\n" would render as a single line in the notification.
        $body = implode('<br>', array_map(e(...), $lines));
        $notification = Notification::make()->title($title)->body($body)->danger();
        $url = $log === null ? null : $this->logUrl($log);

        if ($url !== null) {
            $notification->actions([
                Action::make('view')->label(__('filament-mail-log::filament-mail-log.alerts.open'))->url($url)->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($users);
    }

    private function notifyMail(string $title, string $body): void
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string) config('filament-mail-log.alerts.mail_to', '')))));

        if ($to !== []) {
            Mail::to($to)->send(new BounceAlertMail($title, $body));
        }
    }

    /**
     * The users who may receive alerts: the callback, else everyone the gate lets in (a user at a time, capped).
     *
     * @return list<Authenticatable&Model>
     */
    private function users(): array
    {
        $max = max(1, (int) config('filament-mail-log.alerts.max_recipients', 50));

        if (self::$recipients !== null) {
            $users = [];

            foreach ((self::$recipients)() as $user) {
                if ($user instanceof Model) {
                    $users[] = $user;
                }
            }

            return array_slice($users, 0, $max);
        }

        $ability = config('filament-mail-log.alerts.gate', MailLogPlugin::ALERTS_ABILITY);
        $gate = app(GateContract::class);

        if (!is_string($ability) || $ability === '' || !$gate->has($ability)) {
            return [];
        }

        $class = config('auth.providers.users.model');

        if (!is_string($class) || !is_subclass_of($class, Model::class)) {
            return [];
        }

        // Bounded: the scan walks the users table in chunks and stops at `max_recipients` allowed users or at
        // `scan_limit` users looked at, whichever comes first. A site with thousands of users should use
        // `recipientsUsing()` (one query for the few people who must know) instead of the gate.
        $scanLimit = max(1, (int) config('filament-mail-log.alerts.scan_limit', 1000));
        $users = [];
        $scanned = 0;

        $class::query()->chunkById(100, function ($chunk) use (&$users, &$scanned, $gate, $ability, $max, $scanLimit): bool {
            foreach ($chunk as $user) {
                $scanned++;

                if ($user instanceof Authenticatable && $gate->forUser($user)->allows($ability)) {
                    $users[] = $user;
                }

                if (count($users) >= $max || $scanned >= $scanLimit) {
                    return false;
                }
            }

            return true;
        });

        return $users;
    }

    private function logUrl(MailLog $log): ?string
    {
        try {
            foreach (Filament::getPanels() as $panel) {
                if ($panel->hasPlugin(MailLogPlugin::ID)) {
                    return MailLogResource::getUrl('view', ['record' => $log], panel: $panel->getId());
                }
            }
        } catch (Throwable) {
            // No panel with the plugin: the notification simply has no button.
        }

        return null;
    }
}
