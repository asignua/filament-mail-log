<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Concerns;

use Asignua\FilamentMailLog\Models\MailLog;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * For the models that mails are related to (Order, User ...): the `mailLogs` relationship that the
 * `MailLogsRelationManager` and your own code read.
 *
 *     $order->mailLogs()->latest('id')->get();
 */
trait HasMailLogs
{
    /**
     * @return MorphToMany<MailLog, $this>
     */
    public function mailLogs(): MorphToMany
    {
        return $this->morphToMany(
            MailLog::class,
            'related',
            (string) config('filament-mail-log.relations.table', 'mail_log_relations'),
            'related_id',
            'mail_log_id',
        );
    }
}
