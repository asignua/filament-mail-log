<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\Suppressions;

use Asignua\FilamentMailLog\Enums\SuppressionReason;
use Asignua\FilamentMailLog\Filament\Resources\Suppressions\Pages\ListMailSuppressions;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailSuppression;
use Asignua\FilamentMailLog\Support\TrackingSchema;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Addresses that hard-bounced or complained. Read-only except for "Remove", which has its own permission
 * (a removed address is mailed again, and a complaint is a legal matter in many places).
 */
class MailSuppressionResource extends Resource
{
    protected static ?string $model = MailSuppression::class;

    protected static ?string $recordTitleAttribute = 'email';

    // Next to /mail-logs; the namespace-derived slug would be /suppressions/mail-suppressions.
    protected static ?string $slug = 'mail-suppressions';

    public static function canViewAny(): bool
    {
        return MailLogPlugin::isActive() && MailLogPlugin::get()->canAccess() && TrackingSchema::hasSuppressionsTable();
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
        return static::canRemove();
    }

    public static function canDeleteAny(): bool
    {
        return static::canRemove();
    }

    public static function canRemove(): bool
    {
        return MailLogPlugin::isActive() && MailLogPlugin::get()->canManageSuppressions();
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.suppressions.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.suppressions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.suppressions.plural');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationGroup() : null;
    }

    public static function getNavigationSort(): ?int
    {
        $sort = MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationSort() : null;

        return $sort === null ? null : $sort + 1;
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedNoSymbol;
    }

    public static function table(Table $table): Table
    {
        $t = static fn (string $key): string => __('filament-mail-log::filament-mail-log.'.$key);

        return $table
            ->columns([
                TextColumn::make('created_at')->label($t('columns.date'))->dateTime()->sortable(),
                TextColumn::make('email')->label($t('suppressions.email'))->searchable()->copyable(),
                TextColumn::make('reason')->label($t('suppressions.reason'))->badge(),
                TextColumn::make('provider')->label($t('columns.provider'))->placeholder('—'),
                TextColumn::make('detail')->label($t('columns.detail'))->limit(80)->tooltip(fn (MailSuppression $record): ?string => $record->detail)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('reason')->label($t('suppressions.reason'))->options(SuppressionReason::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    DeleteAction::make()
                        ->label($t('suppressions.remove'))
                        ->modalHeading($t('suppressions.remove_heading'))
                        ->modalDescription($t('suppressions.remove_description'))
                        ->visible(fn (): bool => static::canRemove())
                        ->before(function (): void {
                            // A hidden action can still be called by a crafted Livewire request.
                            abort_unless(static::canRemove(), 403);
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label($t('suppressions.remove'))
                        ->visible(fn (): bool => static::canRemove())
                        ->before(function (): void {
                            abort_unless(static::canRemove(), 403);
                        }),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailSuppressions::route('/'),
        ];
    }
}
