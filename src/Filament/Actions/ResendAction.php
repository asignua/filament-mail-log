<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Actions;

use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailResender;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * "Resend": sends the STORED copy of a message to the same or other recipients. One class for the row action
 * and the header action of the message page.
 */
class ResendAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resend';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $t = static fn (string $key): string => __('filament-mail-log::filament-mail-log.'.$key);

        $this
            ->label($t('resend.action'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->modalHeading($t('resend.heading'))
            ->modalDescription($t('resend.description'))
            ->modalSubmitActionLabel($t('resend.submit'))
            ->modalWidth('2xl')
            ->requiresConfirmation()
            ->visible(fn (?MailLog $record): bool => $record instanceof MailLog
                && MailLogPlugin::isActive()
                && MailLogPlugin::get()->canResend()
                && app(MailResender::class)->isAvailable($record))
            ->fillForm(fn (MailLog $record): array => [
                'to' => $record->recipients['to'] ?? array_filter([$record->recipient]),
                'with_cc' => false,
                'cc' => [],
                'with_bcc' => false,
                'bcc' => [],
            ])
            ->schema([
                Callout::make($t('resend.redacted_title'))
                    ->description($t('resend.redacted_text'))
                    ->warning()
                    ->visible(fn (): bool => $this->log()?->redacted === true),
                Callout::make($t('resend.truncated_title'))
                    ->description($t('resend.truncated_text'))
                    ->warning()
                    ->visible(fn (): bool => $this->log()?->truncated === true),
                Callout::make($t('resend.attachments_title'))
                    ->description($t('resend.attachments_text'))
                    ->info()
                    ->visible(fn (): bool => filled($this->log()?->attachments)),
                TagsInput::make('to')
                    ->label($t('columns.to'))
                    ->placeholder($t('resend.placeholder'))
                    ->helperText($t('resend.to_help'))
                    ->required()
                    ->rules(['array', 'min:1', 'max:50'])
                    ->nestedRecursiveRules(['required', 'string', 'email:rfc', 'max:254']),
                Toggle::make('with_cc')->label($t('resend.add_cc'))->live(),
                TagsInput::make('cc')
                    ->label('Cc')
                    ->visible(fn (callable $get): bool => (bool) $get('with_cc'))
                    ->rules(['array', 'max:50'])
                    ->nestedRecursiveRules(['required', 'string', 'email:rfc', 'max:254']),
                Toggle::make('with_bcc')->label($t('resend.add_bcc'))->live(),
                TagsInput::make('bcc')
                    ->label('Bcc')
                    ->visible(fn (callable $get): bool => (bool) $get('with_bcc'))
                    ->rules(['array', 'max:50'])
                    ->nestedRecursiveRules(['required', 'string', 'email:rfc', 'max:254']),
            ])
            ->action(function (MailLog $record, array $data) use ($t): void {
                // A hidden action can still be called by a crafted Livewire request: check the permission again.
                if (!MailLogPlugin::isActive() || !MailLogPlugin::get()->canResend()) {
                    throw new AuthorizationException;
                }

                $resender = app(MailResender::class);

                if (!$resender->isAvailable($record)) {
                    return;
                }

                try {
                    $resender->resend(
                        $record,
                        $this->addresses($data['to'] ?? []),
                        ($data['with_cc'] ?? false) ? $this->addresses($data['cc'] ?? []) : [],
                        ($data['with_bcc'] ?? false) ? $this->addresses($data['bcc'] ?? []) : [],
                    );
                } catch (Throwable $e) {
                    report($e);

                    Notification::make()->danger()->title($t('resend.failed'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($t(config('filament-mail-log.resend.queue') ? 'resend.queued' : 'resend.sent'))
                    ->send();
            });
    }

    private function log(): ?MailLog
    {
        $record = $this->getRecord();

        return $record instanceof MailLog ? $record : null;
    }

    /**
     * @return list<string>
     */
    private function addresses(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $address): bool => is_string($address) && $address !== '',
        )));
    }
}
