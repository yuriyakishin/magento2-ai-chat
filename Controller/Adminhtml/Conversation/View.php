<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Adminhtml\Conversation;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Yu\AiChat\Model\ConversationRepository;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Yu_AiChat::analytics';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ConversationRepository $conversationRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page|Redirect
     */
    public function execute(): Page|Redirect
    {
        $conversationId = (int)$this->getRequest()->getParam('id');
        try {
            $this->conversationRepository->getById($conversationId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Conversation not found.'));
            return $this->resultRedirectFactory->create()->setPath('aichat/conversation/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Yu_AiChat::conversations');
        $resultPage->getConfig()->getTitle()->prepend(__('Conversation #%1', $conversationId));
        return $resultPage;
    }
}
