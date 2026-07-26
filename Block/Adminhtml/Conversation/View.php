<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml\Conversation;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Yu\AiChat\Model\Conversation;
use Yu\AiChat\Model\ConversationRepository;
use Yu\AiChat\Model\Message;
use Yu\AiChat\Model\MessageRepository;

class View extends Template
{
    private ?Conversation $conversation = null;
    private bool $conversationLoaded = false;

    public function __construct(
        Template\Context $context,
        private readonly ConversationRepository $conversationRepository,
        private readonly MessageRepository $messageRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return Conversation|null
     */
    public function getConversation(): ?Conversation
    {
        if (!$this->conversationLoaded) {
            $this->conversationLoaded = true;
            try {
                $this->conversation = $this->conversationRepository->getById(
                    (int)$this->getRequest()->getParam('id')
                );
            } catch (NoSuchEntityException $e) {
                $this->conversation = null;
            }
        }
        return $this->conversation;
    }

    /**
     * @return Message[]
     */
    public function getMessages(): array
    {
        $conversation = $this->getConversation();
        if ($conversation === null) {
            return [];
        }
        return $this->messageRepository->getListForConversation((int)$conversation->getId());
    }

    /**
     * @param Message $message
     * @return string
     */
    public function getMessageRole(Message $message): string
    {
        return (string)$message->getData('role');
    }

    /**
     * @param Message $message
     * @return string
     */
    public function getMessageContent(Message $message): string
    {
        return (string)$message->getData('content');
    }

    /**
     * Tool messages store a JSON envelope {tool, arguments, result} - render
     * it readably instead of a raw JSON blob.
     *
     * @param string $rawContent
     * @return string
     */
    public function formatToolContent(string $rawContent): string
    {
        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            return $rawContent;
        }
        return sprintf(
            "%s(%s)\n=> %s",
            (string)($data['tool'] ?? '?'),
            (string)json_encode($data['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
            (string)json_encode($data['result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
