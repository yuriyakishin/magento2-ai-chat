<?php
declare(strict_types=1);

namespace Yu\AiChat\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\Data\ChatResultInterface;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Api\Data\PageContextInterface;

/**
 * Channel-agnostic chat entry point. Implementations must not read the
 * HTTP session; the caller supplies the owner.
 */
interface ChatServiceInterface
{
    /**
     * @param ConversationOwnerInterface $owner
     * @param string|null $conversationUuid
     * @param string $message
     * @param PageContextInterface $context
     * @return ChatResultInterface
     * @throws NoSuchEntityException when $conversationUuid is set but not owned by $owner
     */
    public function send(
        ConversationOwnerInterface $owner,
        ?string $conversationUuid,
        string $message,
        PageContextInterface $context
    ): ChatResultInterface;
}
