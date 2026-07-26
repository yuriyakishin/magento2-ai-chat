<?php
declare(strict_types=1);

namespace Yu\AiChat\Ui\Component\Listing;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use Yu\AiChat\Model\Analytics\Reports;

/**
 * Adds "aichat_totals" to the standard grid payload: KPI aggregates over
 * whichever conversations the grid's own filters currently match. The
 * summary panel above the grid reads this straight off the data source
 * (see Yu_AiChat/js/conversation-summary.js), so it stays in sync with the
 * grid without a separate request.
 */
class ConversationDataProvider extends DataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly Reports $reports,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $reporting, $searchCriteriaBuilder, $request,
            $filterBuilder, $meta, $data);
    }

    /**
     * @return array
     */
    public function getData()
    {
        $searchResult = $this->getSearchResult();
        $data = $this->searchResultToOutput($searchResult);
        $data['aichat_totals'] = $this->reports->getTotalsForConversations(
            $this->getFilteredConversationIdSelect($searchResult)
        );
        return $data;
    }

    /**
     * @param SearchResultInterface $searchResult
     * @return Select
     */
    private function getFilteredConversationIdSelect(SearchResultInterface $searchResult): Select
    {
        /** @var \Magento\Framework\Data\Collection\AbstractDb $searchResult */
        $select = clone $searchResult->getSelect();
        $select->reset(Select::LIMIT_COUNT);
        $select->reset(Select::LIMIT_OFFSET);
        $select->reset(Select::ORDER);
        $select->reset(Select::COLUMNS);
        $select->columns('main_table.conversation_id');
        return $select;
    }
}
