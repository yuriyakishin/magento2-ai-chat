<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Api\Data\PageContextInterface;

class ConversationManager
{
    public function __construct(
        private readonly ConversationFactory $conversationFactory,
        private readonly MessageFactory $messageFactory,
        private readonly ConversationRepository $conversationRepository,
        private readonly MessageRepository $messageRepository,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver
    ) {
    }

    /**
     * @param ConversationOwnerInterface $owner
     * @param string|null $uuid
     * @param string $firstMessage
     * @return Conversation
     */
    public function resolveConversation(
        ConversationOwnerInterface $owner,
        ?string $uuid,
        string $firstMessage
    ): Conversation {
        if ($uuid !== null) {
            return $this->conversationRepository->getByUuidForOwner($uuid, $owner);
        }
        $conversation = $this->conversationFactory->create();
        $conversation->setData([
            'uuid' => $this->identityGenerator->generateId(),
            'customer_id' => $owner->getCustomerId(),
            'guest_token' => $owner->getGuestToken(),
            'channel' => $owner->getChannel(),
            'external_id' => $owner->getExternalId(),
            'store_id' => (int)$this->storeManager->getStore()->getId(),
            'locale' => $this->localeResolver->getLocale(),
            'title' => mb_substr($firstMessage, 0, 255),
        ]);
        return $this->conversationRepository->save($conversation);
    }

    /**
     * @param Conversation $conversation
     * @param string $text
     * @param PageContextInterface $context
     * @return Message
     */
    public function addUserMessage(Conversation $conversation, string $text, PageContextInterface $context): Message
    {
        $message = $this->messageFactory->create();
        $message->setData([
            'conversation_id' => (int)$conversation->getId(),
            'role' => 'user',
            'content' => $text,
            'status' => 'complete',
            'page_type' => $context->getPageType(),
            'product_id' => $context->getProductId(),
            'category_id' => $context->getCategoryId(),
            'url' => $context->getUrl(),
            'referrer' => $context->getReferrer(),
            'customer_group_id' => $context->getCustomerGroupId(),
        ]);
        return $this->messageRepository->save($message);
    }

    /**
     * @param Conversation $conversation
     * @param string $text
     * @param array<string, mixed> $meta status, prompt_tokens, completion_tokens, total_tokens, cost, latency_ms, provider, model
     * @return Message
     */
    public function addAssistantMessage(Conversation $conversation, string $text, array $meta = []): Message
    {
        $message = $this->messageFactory->create();
        $message->setData($meta + [
                'conversation_id' => (int)$conversation->getId(),
                'role' => 'assistant',
                'content' => $text,
                'status' => 'complete',
            ]);
        return $this->messageRepository->save($message);
    }

    /**
     * @param Conversation $conversation
     * @param string $tool
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @param PageContextInterface $context
     * @return Message
     */
    public function addToolMessage(
        Conversation $conversation,
        string $tool,
        array $arguments,
        array $result,
        PageContextInterface $context
    ): Message {
        $message = $this->messageFactory->create();
        $message->setData([
            'conversation_id' => (int)$conversation->getId(),
            'role' => 'tool',
            'content' => (string)json_encode(
                ['tool' => $tool, 'arguments' => $arguments, 'result' => $result],
                JSON_UNESCAPED_UNICODE
            ),
            'status' => 'complete',
            'page_type' => $context->getPageType(),
            'product_id' => $context->getProductId(),
            'category_id' => $context->getCategoryId(),
        ]);
        return $this->messageRepository->save($message);
    }

    /**
     * @param Conversation $conversation
     * @param string|null $provider
     * @param string|null $model
     * @return void
     */
    public function touch(Conversation $conversation, ?string $provider = null, ?string $model = null): void
    {
        if ($provider !== null) {
            $conversation->setData('provider', $provider);
        }
        if ($model !== null) {
            $conversation->setData('model', $model);
        }
        $conversation->setDataChanges(true);
        $this->conversationRepository->save($conversation);
    }
}
