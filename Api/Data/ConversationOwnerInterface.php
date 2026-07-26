<?php
declare(strict_types=1);

namespace Yu\AiChat\Api\Data;

/**
 * Identifies who owns a conversation, independent of the channel
 * (web session, customer account, future Telegram chat).
 */
interface ConversationOwnerInterface
{
    /**
     * @return int|null
     */
    public function getCustomerId(): ?int;

    /**
     * @return string|null
     */
    public function getGuestToken(): ?string;

    /**
     * @return string
     */
    public function getChannel(): string;

    /**
     * @return string|null
     */
    public function getExternalId(): ?string;
}
