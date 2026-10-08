<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages;

use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions on purpose: the log is read-only.
 */
class ViewMailLog extends ViewRecord
{
    protected static string $resource = MailLogResource::class;
}
