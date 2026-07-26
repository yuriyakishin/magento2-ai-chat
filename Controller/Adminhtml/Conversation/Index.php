<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Adminhtml\Conversation;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Yu_AiChat::analytics';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Yu_AiChat::conversations');
        $resultPage->getConfig()->getTitle()->prepend(__('AI Chat Conversations'));
        return $resultPage;
    }
}
