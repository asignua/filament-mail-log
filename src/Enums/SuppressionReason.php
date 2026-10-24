<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SuppressionReason: string implements HasColor, HasLabel
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';

    public function getLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.suppression_reasons.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HardBounce => 'danger',
            self::Complaint => 'warning',
        };
    }
}
