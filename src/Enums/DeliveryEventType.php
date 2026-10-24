<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a provider reported about one message, in the plugin's own vocabulary.
 */
enum DeliveryEventType: string implements HasColor, HasLabel
{
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Rejected = 'rejected';
    case Deferred = 'deferred';

    public function getLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.event_types.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::Opened, self::Clicked => 'info',
            self::Deferred => 'warning',
            self::Bounced, self::Complained, self::Rejected => 'danger',
        };
    }
}
