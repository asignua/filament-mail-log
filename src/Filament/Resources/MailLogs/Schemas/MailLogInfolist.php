<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Schemas;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailTypes;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class MailLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $t = static fn (string $key): string => __('filament-mail-log::filament-mail-log.'.$key);

        return $schema->components([
            Tabs::make('mail')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make($t('tabs.details'))->schema([
                        Section::make()->columns(2)->schema([
                            TextEntry::make('status')->label($t('columns.status'))->badge(),
                            TextEntry::make('subject')->label($t('columns.subject'))->placeholder('—'),
                            TextEntry::make('sender')->label($t('columns.sender'))->placeholder('—'),
                            TextEntry::make('recipients.to')->label($t('columns.to'))->listWithLineBreaks()->placeholder('—'),
                            TextEntry::make('recipients.cc')->label('Cc')->listWithLineBreaks()->placeholder('—'),
                            TextEntry::make('recipients.bcc')->label('Bcc')->listWithLineBreaks()->placeholder('—'),
                            TextEntry::make('type')
                                ->label($t('columns.type'))
                                ->placeholder('—')
                                ->formatStateUsing(fn (?string $state): string => MailTypes::label($state))
                                ->helperText(fn (MailLog $record): ?string => $record->type),
                            TextEntry::make('mailer')->label($t('columns.mailer'))->placeholder('—'),
                            TextEntry::make('created_at')->label($t('columns.date'))->dateTime(),
                            TextEntry::make('queued_at')->label($t('columns.queued_at'))->dateTime()->placeholder('—'),
                            TextEntry::make('sent_at')->label($t('columns.sent_at'))->dateTime()->placeholder('—'),
                            TextEntry::make('failed_at')->label($t('columns.failed_at'))->dateTime()->placeholder('—'),
                            TextEntry::make('message_id')->label($t('columns.message_id'))->placeholder('—')->copyable()->fontFamily('mono'),
                            IconEntry::make('redacted')->label($t('columns.redacted'))->boolean(),
                        ]),

                        Section::make($t('sections.error'))
                            ->schema([TextEntry::make('error')->hiddenLabel()->color('danger')])
                            ->visible(fn (MailLog $record): bool => $record->status === MailStatus::Failed && filled($record->error)),

                        Section::make($t('sections.attachments'))
                            ->schema([
                                TextEntry::make('attachment_list')
                                    ->hiddenLabel()
                                    ->listWithLineBreaks()
                                    ->state(fn (MailLog $record): array => self::attachmentLines($record)),
                            ])
                            ->visible(fn (MailLog $record): bool => filled($record->attachments)),

                        TextEntry::make('notice')
                            ->hiddenLabel()
                            ->state($t('notices.redacted'))
                            ->color('warning')
                            ->visible(fn (MailLog $record): bool => $record->redacted),

                        TextEntry::make('truncated_notice')
                            ->hiddenLabel()
                            ->state($t('notices.truncated'))
                            ->color('warning')
                            ->visible(fn (MailLog $record): bool => $record->truncated),
                    ]),

                    Tab::make($t('tabs.html'))->schema([
                        // @phpstan-ignore argument.type (the view namespace is registered at run time)
                        ViewEntry::make('html_body')->hiddenLabel()->view('filament-mail-log::entries.mail-html'),
                    ]),

                    Tab::make($t('tabs.text'))->schema([
                        // @phpstan-ignore argument.type (the view namespace is registered at run time)
                        ViewEntry::make('text_body')->hiddenLabel()->view('filament-mail-log::entries.mail-text'),
                    ]),

                    Tab::make($t('tabs.headers'))->schema([
                        KeyValueEntry::make('header_list')
                            ->hiddenLabel()
                            ->keyLabel($t('columns.header'))
                            ->valueLabel($t('columns.value'))
                            ->state(fn (MailLog $record): array => self::headerRows($record)),
                    ]),
                ]),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function attachmentLines(MailLog $record): array
    {
        $lines = [];

        foreach ($record->attachments ?? [] as $attachment) {
            $size = $attachment['size'] ?? null;
            $lines[] = trim(($attachment['name'] ?? '—').' ('.$attachment['mime'].($size === null ? '' : ', '.self::bytes($size)).')');
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private static function headerRows(MailLog $record): array
    {
        $rows = [];

        foreach ($record->headers ?? [] as $name => $values) {
            $rows[$name] = implode("\n", $values);
        }

        return $rows;
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes < 1_048_576 ? round($bytes / 1024, 1).' KB' : round($bytes / 1_048_576, 1).' MB';
    }
}
