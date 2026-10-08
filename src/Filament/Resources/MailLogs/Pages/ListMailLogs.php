<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages;

use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMailLogs extends ListRecords
{
    protected static string $resource = MailLogResource::class;
}
