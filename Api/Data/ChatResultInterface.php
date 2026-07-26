<?php
declare(strict_types=1);

namespace Yu\AiChat\Api\Data;

interface ChatResultInterface
{
    /**
     * @return string
     */
    public function getConversationUuid(): string;

    /**
     * @return string
     */
    public function getReply(): string;
}
