<?php
declare(strict_types=1);

namespace Yu\AiChat\Controller\Adminhtml\Insights;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Yu\AiChat\Model\Analytics\InsightsGenerator;
use Yu\AiChat\Model\Config;

class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Yu_AiChat::analytics';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly InsightsGenerator $generator
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        if (!$this->config->isInsightsEnabled()) {
            $this->messageManager->addWarningMessage(__('Business Insights are disabled in configuration.'));
        } else {
            try {
                $this->generator->generate();
                $this->messageManager->addSuccessMessage(__('Insights regenerated.'));
            } catch (\RuntimeException $e) {
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }
        }
        return $this->resultRedirectFactory->create()->setPath('aichat/dashboard/index');
    }
}
