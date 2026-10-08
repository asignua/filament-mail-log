<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs;

use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ListMailLogs;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages\ViewMailLog;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Schemas\MailLogInfolist;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\Tables\MailLogsTable;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only list of outgoing messages. Create / edit / delete are closed on the resource itself, not just
 * hidden in the UI: a log that can be edited is worth nothing as an audit trail.
 */
class MailLogResource extends Resource
{
    protected static ?string $model = MailLog::class;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function canViewAny(): bool
    {
        return MailLogPlugin::isActive() && MailLogPlugin::get()->canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.resource.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.resource.plural');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationGroup() : null;
    }

    public static function getNavigationSort(): ?int
    {
        return MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationSort() : null;
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return (MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationIcon() : null) ?? Heroicon::OutlinedInbox;
    }

    public static function table(Table $table): Table
    {
        return MailLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MailLogInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailLogs::route('/'),
            'view' => ViewMailLog::route('/{record}'),
        ];
    }
}
