<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Yu\AiChat\Api\Data\ChatResultInterface;

class ChatResult implements ChatResultInterface
{
    public function __construct(
        private readonly string $conversationUuid,
        private readonly string $reply
    ) {
    }

    /**
     * @return string
     */
    public function getConversationUuid(): string
    {
        return $this->conversationUuid;
    }

    /**
     * @return string
     */
    public function getReply(): string
    {
        return $this->reply;
    }
}
