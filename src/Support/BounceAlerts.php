<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
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

    /** @var (Closure(): iterable<Authenticatable>)|null */
    private static ?Closure $recipients = null;

    /**
     * Replaces the gate lookup with your own list of users (e.g. one team, one role). Return the users to notify.
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

            if (!$this->pass($address)) {
                return false;
            }

            $held = (int) Cache::pull(self::CACHE.'held', 0);
            [$title, $body] = $this->text($event, $log, $kinds, $address, $held, $newlySuppressed);

            $this->notifyDatabase($title, $body, $log);
            $this->notifyMail($title, $body);

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
     * The throttle. The address check comes first, so an address that is already quiet does not use up the ceiling.
     */
    private function pass(string $address): bool
    {
        $throttle = (array) config('filament-mail-log.alerts.throttle', []);
        $perAddress = max(0, (int) ($throttle['per_address_minutes'] ?? 60));
        $max = max(0, (int) ($throttle['max'] ?? 10));
        $window = max(1, (int) ($throttle['window_minutes'] ?? 60));

        if ($perAddress > 0 && $address !== '' && !Cache::add(self::CACHE.'addr:'.sha1($address), 1, now()->addMinutes($perAddress))) {
            return false;
        }

        if ($max === 0) {
            return true;
        }

        Cache::add(self::CACHE.'count', 0, now()->addMinutes($window));
        $count = Cache::increment(self::CACHE.'count');

        if (is_int($count) && $count > $max) {
            Cache::add(self::CACHE.'held', 0, now()->addMinutes($window * 2));
            Cache::increment(self::CACHE.'held');

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $kinds
     *
     * @return array{0: string, 1: string}
     */
    private function text(DeliveryEvent $event, ?MailLog $log, array $kinds, string $address, int $held, bool $newlySuppressed): array
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

        if ($held > 0) {
            $lines[] = $t('line_held', ['count' => $held]);
        }

        return [$title, implode("\n", $lines)];
    }

    private function notifyDatabase(string $title, string $body, ?MailLog $log): void
    {
        if (!(bool) config('filament-mail-log.alerts.database', true)) {
            return;
        }

        $users = $this->users();

        if ($users === []) {
            return;
        }

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

        $users = [];

        $class::query()->orderBy((new $class)->getKeyName())->each(function (Model $user) use (&$users, $gate, $ability, $max): bool {
            if ($user instanceof Authenticatable && $gate->forUser($user)->allows($ability)) {
                $users[] = $user;
            }

            return count($users) < $max;
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
