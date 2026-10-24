<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Enums;

use Filament\Support\Contracts\HasLabel;

enum BounceType: string implements HasLabel
{
    /**
     * The address is wrong or gone: retrying is pointless.
     */
    case Hard = 'hard';

    /**
     * Mailbox full, server down, message too large: it may work later.
     */
    case Soft = 'soft';

    public function getLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.bounce_types.'.$this->value);
    }
}
