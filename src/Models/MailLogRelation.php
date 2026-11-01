<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Models;

use Asignua\FilamentMailLog\Support\LogConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A link between a logged message and a model it is about.
 *
 * @property int $mail_log_id
 * @property string $related_type
 * @property int|string $related_id
 */
class MailLogRelation extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-mail-log.relations.table', 'mail_log_relations');
    }

    public function getConnectionName(): ?string
    {
        return LogConnection::name();
    }

    /**
     * A key as the `related_id` column stores it (`relations.key_type`); null when it can not be stored there.
     */
    public static function normalizeKey(mixed $key): int|string|null
    {
        if (!is_scalar($key) || (string) $key === '') {
            return null;
        }

        $type = config('filament-mail-log.relations.key_type', 'int');
        $text = (string) $key;

        // Validated per column type: PostgreSQL refuses a malformed uuid with an error, MariaDB stores zeros silently.
        return match ($type) {
            'uuid' => Str::isUuid($text) ? $text : null,
            'ulid' => Str::isUlid($text) ? $text : null,
            'string' => mb_substr($text, 0, 64),
            default => is_int($key) || ctype_digit($text) ? (int) $key : null,
        };
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }

    /**
     * @return BelongsTo<MailLog, $this>
     */
    public function mailLog(): BelongsTo
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }
}
