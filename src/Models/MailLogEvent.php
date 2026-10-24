<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Models;

use Asignua\FilamentMailLog\Casts\UnescapedJson;
use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Asignua\FilamentMailLog\Support\LogConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a provider reported about a message. Written by the webhook, read by the panel.
 *
 * @property int $id
 * @property int $mail_log_id
 * @property DeliveryEventType $type
 * @property BounceType|null $bounce
 * @property string $provider
 * @property string $provider_event_id
 * @property string|null $recipient
 * @property string|null $detail
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class MailLogEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-mail-log.webhooks.events_table', 'mail_log_events');
    }

    public function getConnectionName(): ?string
    {
        return LogConnection::name();
    }

    /**
     * @return BelongsTo<MailLog, $this>
     */
    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }

    protected function casts(): array
    {
        return [
            'type' => DeliveryEventType::class,
            'bounce' => BounceType::class,
            'payload' => UnescapedJson::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
