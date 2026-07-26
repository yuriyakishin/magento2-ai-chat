<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Yu\AiChat\Model\Analytics\InsightsGenerator;
use Yu\AiChat\Model\Analytics\Reports;
use Yu\AiChat\Model\Config;

class Dashboard extends Template
{
    use PeriodSelectorTrait;

    public function __construct(
        Template\Context $context,
        private readonly Reports $reports,
        private readonly InsightsGenerator $insightsGenerator,
        private readonly Config $config,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCostByProvider(): array
    {
        return $this->reports->getCostByProvider($this->getPeriod());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPopularQuestions(): array
    {
        return $this->reports->getPopularQuestions($this->getPeriod());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPopularProducts(): array
    {
        return $this->reports->getPopularProducts($this->getPeriod());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMissingProducts(): array
    {
        return $this->reports->getMissingProducts($this->getPeriod());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTopics(): array
    {
        return $this->reports->getTopics($this->getPeriod());
    }

    /**
     * @return bool
     */
    public function isInsightsEnabled(): bool
    {
        return $this->config->isInsightsEnabled();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentSystemTasks(): array
    {
        return $this->reports->getRecentSystemTasks();
    }

    /**
     * @return array{generated_at: string, period_days: int, text: string}|null
     */
    public function getStoredInsights(): ?array
    {
        return $this->insightsGenerator->getStored();
    }

    /**
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * @return string
     */
    public function getRefreshInsightsUrl(): string
    {
        return $this->getUrl('aichat/insights/refresh');
    }
}
