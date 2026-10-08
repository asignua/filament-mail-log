<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Tables;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Repositories\MailLogRepository;
use Asignua\FilamentMailLog\Support\MailTypes;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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

                TextColumn::make('mailer')
                    ->label($t('columns.mailer'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label($t('columns.status'))
                    ->multiple()
                    ->options(MailStatus::class),

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
                ActionGroup::make([ViewAction::make()]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
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
