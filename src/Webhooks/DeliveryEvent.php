<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Webhooks;

use Asignua\FilamentMailLog\Enums\BounceType;
use Asignua\FilamentMailLog\Enums\DeliveryEventType;
use Carbon\CarbonInterface;

/**
 * A provider event in the plugin's vocabulary, what every driver produces.
 */
final readonly class DeliveryEvent
{
    /** The longest event id that is stored as is; a longer one is hashed (keeps the unique index small). */
    private const int MAX_ID = 180;

    public string $id;

    /**
     * @param list<string>         $messageIds provider / transport message ids this event may refer to
     * @param list<string>         $logIds     values of the mail log correlation id found in the payload (header / metadata / tag)
     * @param array<string, mixed> $payload    already cleaned of secrets
     */
    public function __construct(
        public string $provider,
        string $id,
        public DeliveryEventType $type,
        public CarbonInterface $occurredAt,
        public ?string $recipient = null,
        public ?BounceType $bounce = null,
        public array $messageIds = [],
        public array $logIds = [],
        public ?string $detail = null,
        public array $payload = [],
    ) {
        $this->id = strlen($id) > self::MAX_ID ? 'h:'.sha1($id) : $id;
    }

    /**
     * Hard bounces and complaints put the address on the suppression list.
     */
    public function shouldSuppress(): bool
    {
        return $this->type === DeliveryEventType::Complained
            || ($this->type === DeliveryEventType::Bounced && $this->bounce === BounceType::Hard);
    }
}
