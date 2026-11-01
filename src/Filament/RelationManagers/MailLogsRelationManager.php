<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\RelationManagers;

use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Tables\MailLogsTable;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Support\TrackingSchema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Emails" tab of a record: every message related to it. Register it on any resource whose model uses
 * {@see \Asignua\FilamentMailLog\Concerns\HasMailLogs}:
 *
 *     public static function getRelations(): array { return [MailLogsRelationManager::class]; }
 *
 * Read-only; who sees it follows the log's own access rule ({@see MailLogPlugin::canAccess()}), and the
 * panel needs the plugin registered.
 */
class MailLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'mailLogs';

    protected static ?string $recordTitleAttribute = 'subject';

    /** "View" opens the message page of the log. Without it the action is an empty modal that puts the whole message (bodies, headers) into the Livewire snapshot. */
    protected static ?string $relatedResource = MailLogResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-mail-log::filament-mail-log.relations.tab');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MailLogPlugin::isActive()
            && MailLogPlugin::get()->canAccess()
            && TrackingSchema::hasRelationsTable();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $table = MailLogsTable::configure($table);

        // The tab already is "related to this record": the filter for it would be a no-op.
        return $table
            ->heading(__('filament-mail-log::filament-mail-log.relations.heading'))
            ->emptyStateHeading(__('filament-mail-log::filament-mail-log.relations.empty'))
            ->filters(array_values(array_filter(
                $table->getFilters(),
                static fn (mixed $filter): bool => !($filter instanceof Filter && $filter->getName() === 'related'),
            )));
    }
}
