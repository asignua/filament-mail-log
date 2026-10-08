<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Listeners;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Support\MailRedactor;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionProperty;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\TextPart;
use Throwable;

/**
 * Writes every outgoing message to the log and follows it: queued, sending, sent / failed.
 *
 * RULE 1: the log must never break the mail. Every handler swallows its own errors (a missing table on a
 * half-deployed site would otherwise stop ALL mail of the application).
 *
 * RULE 2: {@see self::sending()} returns nothing. MessageSending is dispatched with `until()`, a listener
 * that returns false CANCELS the message.
 */
final class LogOutgoingMail
{
    private const string UNCONFIRMED = 'Delivery was not confirmed: the message was cancelled by a listener, the transport threw an exception, or the process ended before it answered.';

    public function __construct(private readonly MailLogRepository $repository) {}

    public function queued(JobQueued $event): void
    {
        try {
            if (!$this->enabled() || $event->id === null || $event->id === '') {
                return;
            }

            // The sync driver runs the job inline and never has a separate "waiting" state.
            if (config('queue.connections.'.$event->connectionName.'.driver') === 'sync') {
                return;
            }

            $row = $event->job instanceof SendQueuedMailable
                ? $this->describeMailable($event->job)
                : ($event->job instanceof SendQueuedNotifications ? $this->describeNotification($event->job) : null);

            if ($row === null) {
                return;
            }

            $log = new MailLog;
            $log->ulid = (string) Str::ulid();
            $log->job_id = (string) $event->id;
            $log->queue_connection = $event->connectionName;
            $log->status = MailStatus::Queued;
            $log->queued_at = now();
            $log->type = $row['type'];
            $log->mailer = $row['mailer'] ?? (string) config('mail.default');
            $log->subject = MailRedactor::clean($row['subject']);
            $log->recipient = $row['to'][0] ?? '';
            $log->recipients = ['to' => $row['to'], 'cc' => $row['cc'], 'bcc' => $row['bcc']];
            $log->save();
        } catch (Throwable $e) {
            $this->warn('queued', $e);
        }
    }

    public function sending(MessageSending $event): void
    {
        try {
            if (!$this->enabled()) {
                return;
            }

            $email = $event->message;
            $jobId = MailLogContext::jobId();

            // A queued message already has its row: the worker continues it instead of adding a second one.
            $log = $jobId !== null ? $this->repository->findQueuedByJob($jobId, MailLogContext::connection()) : null;
            $log ??= new MailLog;

            // A fresh model has no ulid yet; a continued `queued` row keeps the one it was born with.
            if (!is_string($log->getAttribute('ulid')) || $log->getAttribute('ulid') === '') {
                $log->ulid = (string) Str::ulid();
            }

            $data = $event->data;
            $type = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? $log->type;

            $log->job_id = $jobId;
            $log->queue_connection = $jobId === null ? null : MailLogContext::connection();
            $log->status = MailStatus::Sending;
            $log->type = is_string($type) ? $type : null;
            $log->mailer = is_string($data['mailer'] ?? null) ? $data['mailer'] : (string) config('mail.default');
            $log->subject = MailRedactor::clean($email->getSubject());
            $log->sender = $this->format($email->getFrom()[0] ?? null);
            $to = $this->formatAll($email->getTo());
            $cc = $this->formatAll($email->getCc());
            $bcc = $this->formatAll($email->getBcc());
            $log->recipient = ($email->getTo()[0] ?? $email->getCc()[0] ?? $email->getBcc()[0] ?? null)?->getAddress() ?? '';
            $log->recipients = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
            $log->attachments = $this->attachments($email);
            $log->headers = $this->headers($email);
            $log->error = null;
            $log->redacted = false;
            $log->truncated = false;

            if ((bool) config('filament-mail-log.body.store', true)) {
                $html = $this->body($email->getHtmlBody());
                $text = $this->body($email->getTextBody());

                $log->html_body = $html['text'];
                $log->text_body = $text['text'];
                $log->redacted = $html['redacted'] || $text['redacted'];
                $log->truncated = $html['truncated'] || $text['truncated'];
            } else {
                $log->html_body = null;
                $log->text_body = null;
            }

            $log->save();

            $email->getHeaders()->addTextHeader($this->header(), $log->ulid);

            $this->track($log->ulid);
        } catch (Throwable $e) {
            $this->warn('sending', $e);
        }

        // Returns nothing on purpose, see RULE 2.
    }

    public function sent(MessageSent $event): void
    {
        try {
            if (!$this->enabled()) {
                return;
            }

            $original = $event->sent->getOriginalMessage();
            $ulid = $original instanceof Email ? $original->getHeaders()->get($this->header())?->getBodyAsString() : null;

            if ($ulid === null || $ulid === '') {
                // A transport rebuilt the message and lost the header: the newest one is ours, sending is sequential.
                $ulid = MailLogContext::forgetLast();

                if ($ulid === null) {
                    return;
                }
            } else {
                MailLogContext::forget($ulid);
            }

            $this->repository->markSent($ulid, $event->sent->getMessageId());
        } catch (Throwable $e) {
            $this->warn('sent', $e);
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        try {
            $id = $event->job->getJobId();
            $sync = $event->connectionName === 'sync' || config('queue.connections.'.$event->connectionName.'.driver') === 'sync';

            // A sync job runs inside the request / job that dispatched it: keep that frame for later.
            MailLogContext::startJob($id === '' ? null : (string) $id, $event->connectionName, $sync);
        } catch (Throwable $e) {
            $this->warn('job start', $e);
        }
    }

    public function jobProcessed(JobProcessed $event): void
    {
        try {
            // The job caught the transport exception itself, so no exception event fired: whatever is still
            // "sending" never got an answer.
            $this->repository->markFailedIfSending(MailLogContext::flush(), self::UNCONFIRMED);

            // A job that returned without sending (notification vetoed, model deleted) leaves its `queued` row.
            $this->failQueued($event->job->getJobId(), $event->connectionName, 'The queued job finished without sending the message.');
        } catch (Throwable $e) {
            $this->warn('job processed', $e);
        } finally {
            MailLogContext::endJob();
        }
    }

    public function jobExceptionOccurred(JobExceptionOccurred $event): void
    {
        try {
            $error = Str::limit($event->exception->getMessage(), 1000);
            $this->repository->markFailedIfSending(MailLogContext::flush(), $error);

            // A job that will be retried keeps its `queued` row for the next attempt (same job id on redis /
            // SQS); only the final failure (JobFailed, or already failed here) closes it.
            if ($event->job->hasFailed()) {
                $this->failQueued($event->job->getJobId(), $event->connectionName, $error);
            }
        } catch (Throwable $e) {
            $this->warn('job exception', $e);
        } finally {
            MailLogContext::endJob();
        }
    }

    public function jobFailed(JobFailed $event): void
    {
        try {
            $this->failQueued($event->job->getJobId(), $event->connectionName, Str::limit($event->exception->getMessage(), 1000));
        } catch (Throwable $e) {
            $this->warn('job failed', $e);
        }
    }

    /**
     * Called at the end of a request / console command: whatever is still "sending" never got an answer
     * from the transport (it threw, or the process is leaving). Public for tests.
     */
    public function failUnconfirmed(): void
    {
        try {
            $this->repository->markFailedIfSending(
                MailLogContext::flush(),
                self::UNCONFIRMED,
            );
        } catch (Throwable $e) {
            $this->warn('terminate', $e);
        }
    }

    private function track(string $ulid): void
    {
        $first = MailLogContext::isEmpty();
        MailLogContext::push($ulid);

        // Inside a queue job the exception events do the work. Outside, the end of the process is the only
        // signal that the transport never answered (one callback per burst, so Octane / long processes do not pile them up).
        if ($first && MailLogContext::jobId() === null) {
            app()->terminating($this->failUnconfirmed(...));
        }
    }

    private function failQueued(string|int|null $jobId, ?string $connection, string $error): void
    {
        if ($jobId !== null && $jobId !== '') {
            $this->repository->markFailedIfQueued((string) $jobId, $connection, $error);
        }
    }

    private function enabled(): bool
    {
        return (bool) config('filament-mail-log.enabled', true);
    }

    private function header(): string
    {
        $header = config('filament-mail-log.header', 'X-Mail-Log-Id');

        return is_string($header) && $header !== '' ? $header : 'X-Mail-Log-Id';
    }

    private function warn(string $stage, Throwable $e): void
    {
        Log::warning("filament-mail-log: could not record the message ({$stage}): ".$e->getMessage());
    }

    /**
     * @return array{text: ?string, redacted: bool, truncated: bool}
     */
    private function body(mixed $body): array
    {
        // A resource body must not be read here: the cursor would move and the transport would send a cut message.
        if (!is_string($body) || $body === '') {
            return ['text' => null, 'redacted' => false, 'truncated' => false];
        }

        $max = (int) config('filament-mail-log.body.max_bytes', 200_000);
        $truncated = false;

        if ($max > 0 && strlen($body) > $max) {
            $body = mb_strcut($body, 0, $max, 'UTF-8');
            $truncated = true;
        }

        // Invalid UTF-8 would make the INSERT fail on MySQL and the JSON of the panel too.
        $result = MailRedactor::apply(mb_scrub($body, 'UTF-8'));

        return ['text' => $result->text, 'redacted' => $result->redacted, 'truncated' => $truncated];
    }

    /**
     * @return array<string, list<string>>|null
     */
    private function headers(Email $email): ?array
    {
        if (!(bool) config('filament-mail-log.headers.store', true)) {
            return null;
        }

        $ignore = array_map(
            static fn (mixed $name): string => strtolower((string) $name),
            (array) config('filament-mail-log.headers.ignore', []),
        );
        $ignore[] = strtolower($this->header());

        $rows = [];

        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();

            if (in_array(strtolower($name), $ignore, true)) {
                continue;
            }

            $rows[$name][] = (string) MailRedactor::clean(mb_scrub($header->getBodyAsString(), 'UTF-8'));
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @return list<array{name: ?string, mime: string, size: ?int}>|null
     */
    private function attachments(Email $email): ?array
    {
        $withSizes = (bool) config('filament-mail-log.attachments.sizes', true);
        $rows = [];

        foreach ($email->getAttachments() as $part) {
            $size = null;

            if ($withSizes) {
                $size = $this->attachmentSize($part);
            }

            $rows[] = [
                'name' => $part->getFilename(),
                'mime' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'size' => $size,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * Never calls getBody(): for a stream resource it reads the stream to the end and the transport would
     * then send an empty attachment. Strings and files are measured without touching a cursor.
     */
    private function attachmentSize(DataPart $part): ?int
    {
        try {
            $body = (new ReflectionProperty(TextPart::class, 'body'))->getValue($part);

            if (is_string($body)) {
                return strlen($body);
            }

            return $body instanceof File ? $body->getSize() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function format(?Address $address): ?string
    {
        if ($address === null) {
            return null;
        }

        return $address->getName() === '' ? $address->getAddress() : $address->getName().' <'.$address->getAddress().'>';
    }

    /**
     * @param array<array-key, Address> $addresses
     *
     * @return list<string>
     */
    private function formatAll(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => (string) $this->format($address), $addresses));
    }

    /**
     * @return array{type: string, mailer: ?string, subject: ?string, to: list<string>, cc: list<string>, bcc: list<string>}|null
     */
    private function describeMailable(SendQueuedMailable $job): ?array
    {
        $mailable = $job->mailable;

        if (!$mailable instanceof Mailable) {
            return null;
        }

        return [
            'type' => $mailable::class,
            'mailer' => $mailable->mailer ?: null,
            'subject' => $mailable->subject ?: null,
            'to' => $this->mailableAddresses($mailable->to),
            'cc' => $this->mailableAddresses($mailable->cc),
            'bcc' => $this->mailableAddresses($mailable->bcc),
        ];
    }

    /**
     * @param array<array-key, mixed> $addresses
     *
     * @return list<string>
     */
    private function mailableAddresses(array $addresses): array
    {
        $rows = [];

        foreach ($addresses as $address) {
            if (!is_array($address) || !is_string($address['address'] ?? null)) {
                continue;
            }

            $name = $address['name'] ?? null;
            $rows[] = is_string($name) && $name !== '' ? $name.' <'.$address['address'].'>' : $address['address'];
        }

        return $rows;
    }

    /**
     * @return array{type: string, mailer: ?string, subject: ?string, to: list<string>, cc: list<string>, bcc: list<string>}|null
     */
    private function describeNotification(SendQueuedNotifications $job): ?array
    {
        // `channels` is null when the notification was queued without an explicit channel list.
        $channels = $job->channels ?: [];

        if ($channels !== [] && !in_array('mail', $channels, true)) {
            return null;
        }

        $notifiable = $job->notifiables->first();
        $route = null;

        if ($notifiable instanceof AnonymousNotifiable || (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor'))) {
            $route = $notifiable->routeNotificationFor('mail', $job->notification);
        }

        // A route is an address, a list of addresses, or ['address' => 'Name'].
        $addresses = [];

        foreach ((array) $route as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $addresses[] = $value.' <'.$key.'>';
            } elseif (is_string($value)) {
                $addresses[] = $value;
            }
        }

        return [
            'type' => $job->notification::class,
            'mailer' => null,
            'subject' => null,
            'to' => $addresses,
            'cc' => [],
            'bcc' => [],
        ];
    }
}
