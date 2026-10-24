<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Models;

use Asignua\FilamentMailLog\Casts\UnescapedJson;
use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Support\LogConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One outgoing message. Written by {@see \Asignua\FilamentMailLog\Listeners\LogOutgoingMail}, read by the panel.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $job_id
 * @property string|null $job_uuid
 * @property string|null $queue_connection
 * @property string|null $message_id
 * @property string|null $mailer
 * @property string|null $type
 * @property string|null $subject
 * @property string|null $sender
 * @property string $recipient
 * @property array{to?: list<string>, cc?: list<string>, bcc?: list<string>}|null $recipients
 * @property MailStatus $status
 * @property string|null $error
 * @property string|null $html_body
 * @property string|null $text_body
 * @property bool $redacted
 * @property bool $truncated
 * @property list<array{name: ?string, mime: string, size: ?int}>|null $attachments
 * @property array<string, list<string>>|null $headers
 * @property int|null $resent_from_id
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MailLog extends Model
{
    /** The listener fills every column itself; nothing here comes from a request. */
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-mail-log.table', 'mail_logs');
    }

    public function getConnectionName(): ?string
    {
        return LogConnection::name();
    }

    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Whether the `resent_from_id` column exists. A host that updated the package but has not published and run
     * the new migration yet must keep logging: writing an unknown column would lose the row (the listener
     * swallows errors by design), so every resend feature checks this first.
     */
    public static function hasResendColumn(): bool
    {
        $instance = new self;
        $key = ($instance->getConnectionName() ?? '').'.'.$instance->getTable();

        if (!array_key_exists($key, self::$columnCache)) {
            try {
                self::$columnCache[$key] = Schema::connection($instance->getConnectionName())->hasColumn($instance->getTable(), 'resent_from_id');
            } catch (Throwable) {
                return false;
            }
        }

        return self::$columnCache[$key];
    }

    public static function forgetColumnCache(): void
    {
        self::$columnCache = [];
    }

    protected function casts(): array
    {
        return [
            'status' => MailStatus::class,
            'recipients' => UnescapedJson::class,
            'attachments' => UnescapedJson::class,
            'headers' => UnescapedJson::class,
            'resent_from_id' => 'integer',
            'redacted' => 'boolean',
            'truncated' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
