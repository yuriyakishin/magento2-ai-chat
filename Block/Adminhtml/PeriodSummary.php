<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml;

use Magento\Framework\View\Element\Template;
use Yu\AiChat\Model\Analytics\Reports;

/**
 * Period switcher + KPI row, reused on any admin page that reports over
 * Yu\AiChat\Model\Analytics\Reports (currently: Dashboard, Conversations).
 */
class PeriodSummary extends Template
{
    use PeriodSelectorTrait;

    public function __construct(
        Template\Context $context,
        private readonly Reports $reports,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{conversations:int, user_messages:int, avg_latency_ms:int, total_tokens:int, total_cost:float}
     */
    public function getTotals(): array
    {
        return $this->reports->getTotals($this->getPeriod());
    }

    /**
     * @param string $period
     * @return string
     */
    public function getPeriodUrl(string $period): string
    {
        return $this->getUrl((string)$this->getData('period_url_path'), ['period' => $period]);
    }

    /**
     * @return string|null
     */
    public function getConversationsUrl(): ?string
    {
        return $this->getData('show_conversations_link') ? $this->getUrl('aichat/conversation/index') : null;
    }
}
