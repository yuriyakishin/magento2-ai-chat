<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Conversation;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\ConversationRepository;
use Yu\AiChat\Model\MessageRepository;
use Yu\AiChat\Model\OwnershipResolver;

class View implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly OwnershipResolver $ownershipResolver,
        private readonly ConversationRepository $conversationRepository,
        private readonly MessageRepository $messageRepository
    ) {
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'Not found.']);
        }
        $uuid = (string)$this->request->getParam('uuid');
        $owner = $this->ownershipResolver->resolve();
        if ($uuid === '' || $owner === null) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'Conversation not found.']);
        }
        try {
            $conversation = $this->conversationRepository->getByUuidForOwner($uuid, $owner);
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'Conversation not found.']);
        }
        $messages = [];
        foreach ($this->messageRepository->getListForConversation((int)$conversation->getId()) as $message) {
            // Tool traffic is internal: analytics data, never widget content.
            if (!in_array($message->getData('role'), ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = [
                'role' => $message->getData('role'),
                'content' => $message->getData('content'),
                'created_at' => $message->getData('created_at'),
            ];
        }
        return $result->setData([
            'conversation' => [
                'uuid' => $conversation->getData('uuid'),
                'title' => $conversation->getData('title'),
            ],
            'messages' => $messages,
        ]);
    }
}
