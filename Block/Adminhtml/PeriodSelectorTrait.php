<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml;

/**
 * Shared "Today / Yesterday / N days" period selector, read from the
 * "period" request param. Used by any admin block that reports over
 * Yu\AiChat\Model\Analytics\Reports.
 */
trait PeriodSelectorTrait
{
    private const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7' => '7 days',
        '30' => '30 days',
        '90' => '90 days',
    ];
    private const DEFAULT_PERIOD = '30';

    /**
     * @return string
     */
    public function getPeriod(): string
    {
        $period = (string)$this->getRequest()->getParam('period', self::DEFAULT_PERIOD);
        return array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * @return array<string, string> period key => label
     */
    public function getAvailablePeriods(): array
    {
        return self::PERIODS;
    }
}
