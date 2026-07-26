<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Yu\AiChat\Model\ResourceModel\Message as MessageResource;
use Yu\AiChat\Model\ResourceModel\Message\CollectionFactory;

class MessageRepository
{
    public function __construct(
        private readonly MessageResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param Message $message
     * @return Message
     */
    public function save(Message $message): Message
    {
        $this->resource->save($message);
        return $message;
    }

    /**
     * @param int $conversationId
     * @return Message[]
     */
    public function getListForConversation(int $conversationId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('conversation_id', $conversationId);
        $collection->setOrder('created_at', 'ASC');
        $collection->setOrder('message_id', 'ASC');
        return array_values($collection->getItems());
    }
}
