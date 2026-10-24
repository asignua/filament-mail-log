<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\Suppressions\Pages;

use Asignua\FilamentMailLog\Filament\Resources\Suppressions\MailSuppressionResource;
use Filament\Resources\Pages\ListRecords;

class ListMailSuppressions extends ListRecords
{
    protected static string $resource = MailSuppressionResource::class;
}
