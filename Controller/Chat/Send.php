<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Chat;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\ChatServiceInterface;
use Yu\AiChat\Api\Data\PageContextInterface;
use Yu\AiChat\Api\Data\PageContextInterfaceFactory;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\OwnershipResolver;

class Send implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_MESSAGE_LENGTH = 4000;
    private const MAX_URL_LENGTH = 1024;
    private const PAGE_TYPES = ['home', 'category', 'product', 'cms', 'other'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly OwnershipResolver $ownershipResolver,
        private readonly ChatServiceInterface $chatService,
        private readonly CustomerSession $customerSession,
        private readonly PageContextInterfaceFactory $pageContextFactory
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
        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setHttpResponseCode(403)->setData(['error' => 'Invalid form key.']);
        }
        $message = trim((string)$this->request->getParam('message'));
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return $result->setHttpResponseCode(400)
                ->setData(['error' => 'Message must be between 1 and 4000 characters.']);
        }
        $uuid = (string)$this->request->getParam('conversation_uuid') ?: null;
        $owner = $this->ownershipResolver->resolveOrCreate();
        try {
            $chatResult = $this->chatService->send($owner, $uuid, $message, $this->buildContext());
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'Conversation not found.']);
        }
        return $result->setData([
            'conversation_uuid' => $chatResult->getConversationUuid(),
            'message' => ['role' => 'assistant', 'content' => $chatResult->getReply()],
        ]);
    }

    /**
     * @return PageContextInterface
     */
    private function buildContext(): PageContextInterface
    {
        $ctx = (array)$this->request->getParam('context', []);
        $pageType = in_array($ctx['page_type'] ?? '', self::PAGE_TYPES, true) ? $ctx['page_type'] : 'other';
        return $this->pageContextFactory->create([
            'pageType' => $pageType,
            'productId' => (int)($ctx['product_id'] ?? 0) ?: null,
            'categoryId' => (int)($ctx['category_id'] ?? 0) ?: null,
            'url' => mb_substr((string)($ctx['url'] ?? ''), 0, self::MAX_URL_LENGTH) ?: null,
            'referrer' => mb_substr((string)($ctx['referrer'] ?? ''), 0, self::MAX_URL_LENGTH) ?: null,
            'customerGroupId' => (int)$this->customerSession->getCustomerGroupId(),
        ]);
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    // Form key is validated manually in execute() so the failure response
    // is JSON with a 403 instead of the default redirect.
    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
