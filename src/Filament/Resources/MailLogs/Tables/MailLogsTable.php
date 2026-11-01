<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Tables;

use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Filament\Actions\ResendAction;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Repositories\MailLogRelationRepository;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\MailTypes;
use Asignua\FilamentMailLog\Support\RelatedLabel;
use Asignua\FilamentMailLog\Support\TrackingSchema;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MailLogsTable
{
    public static function configure(Table $table): Table
    {
        $t = static fn (string $key): string => __('filament-mail-log::filament-mail-log.'.$key);

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label($t('columns.date'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('status')
                    ->label($t('columns.status'))
                    ->badge(),

                // Needs the delivery migration; hidden (not broken) until the host ran it.
                TextColumn::make('delivery_status')
                    ->label($t('columns.delivery_status'))
                    ->badge()
                    ->placeholder('—')
                    ->visible(fn (): bool => TrackingSchema::hasStatusColumn()),

                TextColumn::make('recipient')
                    ->label($t('columns.recipient'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $inner): Builder => self::likeAny($inner, ['recipient', 'recipients'], $search),
                    )),

                TextColumn::make('subject')
                    ->label($t('columns.subject'))
                    ->limit(60)
                    ->tooltip(fn (MailLog $record): ?string => $record->subject)
                    ->searchable(),

                TextColumn::make('type')
                    ->label($t('columns.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => MailTypes::label($state)),

                IconColumn::make('redacted')
                    ->label($t('columns.redacted'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resent_from_id')
                    ->label($t('columns.resent_from'))
                    ->formatStateUsing(fn (?int $state): string => '#'.$state)
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('mailer')
                    ->label($t('columns.mailer'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label($t('columns.status'))
                    ->multiple()
                    ->options(MailStatus::class),

                SelectFilter::make('delivery_status')
                    ->label($t('columns.delivery_status'))
                    ->multiple()
                    ->options(DeliveryStatus::class)
                    ->visible(fn (): bool => TrackingSchema::hasStatusColumn()),

                SelectFilter::make('type')
                    ->label($t('columns.type'))
                    ->options(fn (): array => self::typeOptions()),

                Filter::make('recipient')
                    ->schema([TextInput::make('recipient')->label($t('filters.recipient'))])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        is_string($data['recipient'] ?? null) && $data['recipient'] !== '',
                        fn (Builder $q): Builder => $q->where(
                            fn (Builder $inner): Builder => self::likeAny($inner, ['recipient', 'recipients'], (string) $data['recipient']),
                        ),
                    )),

                // Which record the message is about (see the `relations` config). Hidden until the table is migrated.
                Filter::make('related')
                    ->label($t('filters.related'))
                    ->schema([
                        Select::make('related_type')
                            ->label($t('filters.related_type'))
                            ->options(fn (): array => RelatedLabel::typeOptions())
                            ->placeholder($t('filters.any')),
                        TextInput::make('related_id')->label($t('filters.related_id')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => self::relatedQuery($query, $data))
                    ->indicateUsing(fn (array $data): array => self::relatedIndicators($data))
                    ->visible(fn (): bool => TrackingSchema::hasRelationsTable()),

                // The body is a longText: searching it is a separate, explicit filter, never part of the
                // global search where every keystroke would scan the heaviest column.
                Filter::make('body')
                    ->schema([TextInput::make('body')->label($t('filters.body'))])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        is_string($data['body'] ?? null) && $data['body'] !== '',
                        fn (Builder $q): Builder => $q->where(
                            fn (Builder $inner): Builder => self::likeAny($inner, ['html_body', 'text_body'], (string) $data['body']),
                        ),
                    )),

                // A range on created_at, not whereDate(): keeps the index usable, and the day borders are the
                // panel's timezone converted to the database one, like the dates shown in the table.
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label($t('filters.from')),
                        DatePicker::make('until')->label($t('filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, mixed $date): Builder => $q->where('created_at', '>=', self::boundary($date, false)),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, mixed $date): Builder => $q->where('created_at', '<=', self::boundary($date, true)),
                        )),
            ])
            ->recordActions([
                ActionGroup::make([ViewAction::make(), ResendAction::make()]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * @param Builder<MailLog>     $query
     * @param array<string, mixed> $data
     *
     * @return Builder<MailLog>
     */
    private static function relatedQuery(Builder $query, array $data): Builder
    {
        $type = is_string($data['related_type'] ?? null) && $data['related_type'] !== '' ? $data['related_type'] : null;
        $id = is_scalar($data['related_id'] ?? null) && trim((string) $data['related_id']) !== '' ? trim((string) $data['related_id']) : null;

        if ($type === null && $id === null) {
            return $query;
        }

        return $query->whereIn('id', app(MailLogRelationRepository::class)->query()
            ->when($type !== null, fn (Builder $q): Builder => $q->where('related_type', $type))
            ->when($id !== null, fn (Builder $q): Builder => $q->where('related_id', $id))
            ->select('mail_log_id'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function relatedIndicators(array $data): array
    {
        $type = is_string($data['related_type'] ?? null) && $data['related_type'] !== '' ? RelatedLabel::typeLabel($data['related_type']) : null;
        $id = is_scalar($data['related_id'] ?? null) && trim((string) $data['related_id']) !== '' ? '#'.trim((string) $data['related_id']) : null;
        $text = trim(($type ?? '').' '.($id ?? ''));

        return $text === '' ? [] : [__('filament-mail-log::filament-mail-log.filters.related').': '.$text];
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (app(MailLogRepository::class)->distinctTypes() as $type) {
            $options[$type] = MailTypes::label($type);
        }

        return $options;
    }

    /**
     * `column LIKE %needle%` OR-ed over the columns. The wildcard characters of the needle are escaped with
     * an explicit ESCAPE clause (SQLite has no default escape character); a JSON column is cast to text
     * on PostgreSQL, where `json LIKE` does not exist.
     *
     * @param Builder<MailLog> $query
     * @param list<string>     $columns
     *
     * @return Builder<MailLog>
     */
    private static function likeAny(Builder $query, array $columns, string $needle): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
        $pgsql = $query->getModel()->getConnection()->getDriverName() === 'pgsql';

        foreach ($columns as $column) {
            $wrapped = $query->getGrammar()->wrap($column);
            $sql = $pgsql ? $wrapped.'::text ILIKE ? ESCAPE \'!\'' : $wrapped.' LIKE ? ESCAPE \'!\'';

            // @phpstan-ignore argument.type (the column name is wrapped by the grammar, the needle is a binding)
            $query->orWhereRaw($sql, [$pattern]);
        }

        return $query;
    }

    private static function boundary(mixed $date, bool $end): Carbon
    {
        $day = Carbon::parse(is_string($date) ? $date : 'now', FilamentTimezone::get());

        return ($end ? $day->endOfDay() : $day->startOfDay())->setTimezone((string) config('app.timezone'));
    }
}
