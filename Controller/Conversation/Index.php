<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Conversation;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\ConversationRepository;
use Yu\AiChat\Model\OwnershipResolver;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly OwnershipResolver $ownershipResolver,
        private readonly ConversationRepository $conversationRepository
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
        $owner = $this->ownershipResolver->resolve();
        $conversations = [];
        if ($owner !== null) {
            foreach ($this->conversationRepository->getListForOwner($owner) as $conversation) {
                $conversations[] = [
                    'uuid' => $conversation->getData('uuid'),
                    'title' => $conversation->getData('title'),
                    'updated_at' => $conversation->getData('updated_at'),
                ];
            }
        }
        return $result->setData(['conversations' => $conversations]);
    }
}
