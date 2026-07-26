<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Yu\AiChat\Api\Data\ConversationOwnerInterface;

/**
 * Identifies who owns a conversation, independent of the channel
 * (web session, customer account, future Telegram chat).
 */
class ConversationOwner implements ConversationOwnerInterface
{
    public function __construct(
        private readonly ?int $customerId,
        private readonly ?string $guestToken,
        private readonly string $channel = 'web',
        private readonly ?string $externalId = null
    ) {
    }

    /**
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * @return string|null
     */
    public function getGuestToken(): ?string
    {
        return $this->guestToken;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @return string|null
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }
}
