<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The latest meaningful thing a provider told us about a message (the denormalized `delivery_status` column).
 *
 * PRECEDENCE: a status replaces the stored one only when its {@see self::rank()} is HIGHER. Webhooks arrive
 * out of order and are retried, so the rule has to be order-independent:
 *
 *   deferred < soft bounce < delivered < opened < clicked < rejected < hard bounce < complaint
 *
 * A "delivered" that shows up after a hard bounce (late retry) therefore never hides the bounce, a soft bounce
 * followed by a successful retry becomes "delivered", and engagement (opened / clicked) beats plain delivery.
 */
enum DeliveryStatus: string implements HasColor, HasLabel
{
    case Deferred = 'deferred';
    case SoftBounced = 'soft_bounced';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Rejected = 'rejected';
    case Bounced = 'bounced';
    case Complained = 'complained';

    public static function fromEvent(DeliveryEventType $type, ?BounceType $bounce): self
    {
        return match ($type) {
            DeliveryEventType::Delivered => self::Delivered,
            DeliveryEventType::Opened => self::Opened,
            DeliveryEventType::Clicked => self::Clicked,
            DeliveryEventType::Rejected => self::Rejected,
            DeliveryEventType::Deferred => self::Deferred,
            DeliveryEventType::Complained => self::Complained,
            DeliveryEventType::Bounced => $bounce === BounceType::Soft ? self::SoftBounced : self::Bounced,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Deferred => 1,
            self::SoftBounced => 2,
            self::Delivered => 3,
            self::Opened => 4,
            self::Clicked => 5,
            self::Rejected => 6,
            self::Bounced => 7,
            self::Complained => 8,
        };
    }

    /**
     * Values of every status that this one is allowed to replace.
     *
     * @return list<string>
     */
    public function replaces(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->rank() < $this->rank()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    public function getLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.delivery_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::Opened, self::Clicked => 'info',
            self::Deferred, self::SoftBounced => 'warning',
            self::Rejected, self::Bounced, self::Complained => 'danger',
        };
    }
}
