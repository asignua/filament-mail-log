<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Resources\MailLogs\Pages;

use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The log is read-only. The only header action is a per-view switch for remote images in the preview.
 */
class ViewMailLog extends ViewRecord
{
    protected static string $resource = MailLogResource::class;

    /** Remote images are blocked until the viewer asks for them (open-tracking pixels, IP leak). */
    public bool $remoteImages = false;

    protected function getHeaderActions(): array
    {
        $t = static fn (string $key): string => __('filament-mail-log::filament-mail-log.'.$key);

        return [
            Action::make('remoteImages')
                ->label(fn (): string => $this->remoteImages ? $t('actions.block_images') : $t('actions.load_images'))
                ->icon(fn (): string => $this->remoteImages ? 'heroicon-o-eye-slash' : 'heroicon-o-photo')
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->getAttribute('html_body')))
                ->action(function (): void {
                    $this->remoteImages = !$this->remoteImages;
                }),
        ];
    }
}
