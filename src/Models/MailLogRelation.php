<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Models;

use Asignua\FilamentMailLog\Support\LogConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A link between a logged message and a model it is about.
 *
 * @property int $mail_log_id
 * @property string $related_type
 * @property string $related_id
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
