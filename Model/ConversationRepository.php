<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Model\ResourceModel\Conversation as ConversationResource;
use Yu\AiChat\Model\ResourceModel\Conversation\Collection;
use Yu\AiChat\Model\ResourceModel\Conversation\CollectionFactory;

class ConversationRepository
{
    public function __construct(
        private readonly ConversationResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param Conversation $conversation
     * @return Conversation
     */
    public function save(Conversation $conversation): Conversation
    {
        $this->resource->save($conversation);
        return $conversation;
    }

    /**
     * Admin-only lookup: unlike getByUuidForOwner(), not scoped to a
     * particular visitor - the admin can view any conversation.
     *
     * @param int $conversationId
     * @return Conversation
     * @throws NoSuchEntityException when the conversation does not exist
     */
    public function getById(int $conversationId): Conversation
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('conversation_id', $conversationId);
        /** @var Conversation $conversation */
        $conversation = $collection->getFirstItem();
        if (!$conversation->getId()) {
            throw new NoSuchEntityException(__('Conversation not found.'));
        }
        return $conversation;
    }

    /**
     * @param string $uuid
     * @param ConversationOwnerInterface $owner
     * @return Conversation
     * @throws NoSuchEntityException when the conversation does not exist
     *         or belongs to another owner (indistinguishable on purpose)
     */
    public function getByUuidForOwner(string $uuid, ConversationOwnerInterface $owner): Conversation
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('uuid', $uuid);
        $this->addOwnerFilter($collection, $owner);
        /** @var Conversation $conversation */
        $conversation = $collection->getFirstItem();
        if (!$conversation->getId()) {
            throw new NoSuchEntityException(__('Conversation not found.'));
        }
        return $conversation;
    }

    /**
     * @param ConversationOwnerInterface $owner
     * @param int $limit
     * @return Conversation[]
     */
    public function getListForOwner(ConversationOwnerInterface $owner, int $limit = 20): array
    {
        $collection = $this->collectionFactory->create();
        $this->addOwnerFilter($collection, $owner);
        $collection->setOrder('updated_at', 'DESC');
        $collection->setPageSize($limit);
        return array_values($collection->getItems());
    }

    /**
     * @param Collection $collection
     * @param ConversationOwnerInterface $owner
     * @return void
     */
    private function addOwnerFilter(Collection $collection, ConversationOwnerInterface $owner): void
    {
        if ($owner->getCustomerId() !== null) {
            $collection->addFieldToFilter('customer_id', $owner->getCustomerId());
        } else {
            // Callers guarantee a guest token when customer_id is null.
            $collection->addFieldToFilter('guest_token', (string)$owner->getGuestToken());
        }
    }
}
