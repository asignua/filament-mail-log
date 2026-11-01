<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A Mailable or a Notification that knows which models it is about. The log stores a link for each of them, and
 * the `MailLogsRelationManager` of those models lists the message.
 */
interface RelatesToMailLog
{
    /**
     * @return iterable<Model>
     */
    public function mailLogRelations(): iterable;
}
