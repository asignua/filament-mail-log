<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MailStatus: string implements HasColor, HasLabel
{
    /**
     * Pushed to a queue, no worker has taken it yet.
     */
    case Queued = 'queued';

    /**
     * Handed to the mailer, the transport has not answered yet.
     */
    case Sending = 'sending';

    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Never handed to the transport: every recipient is on the suppression list.
     */
    case Suppressed = 'suppressed';

    public function getLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Sending => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Suppressed => 'warning',
        };
    }
}
