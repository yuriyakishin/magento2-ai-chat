<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Ui\Component\Listing;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Analytics\Reports;
use Yu\AiChat\Ui\Component\Listing\ConversationDataProvider;

class ConversationDataProviderTest extends TestCase
{
    public function testGetDataAddsAichatTotalsFromReportsScopedToTheFilteredSelect(): void
    {
        // getSearchResult()/searchResultToOutput() are Magento's own grid
        // machinery (reporting + search criteria); stubbing them lets this
        // test focus on the ~15 lines this class actually adds, instead of
        // re-testing the base DataProvider's own plumbing.
        $reports = $this->createMock(Reports::class);
        $reports->expects($this->once())
            ->method('getTotalsForConversations')
            ->with($this->isInstanceOf(Select::class))
            ->willReturn(['conversations' => 3, 'user_messages' => 9, 'avg_latency_ms' => 500, 'total_tokens' => 100, 'total_cost' => 0.5]);

        $select = $this->createMock(Select::class);
        $select->method('reset')->willReturnSelf();
        $searchResult = $this->getMockBuilder(SearchResult::class)->disableOriginalConstructor()->getMock();
        $searchResult->method('getSelect')->willReturn($select);

        $provider = $this->makeProvider($reports, $searchResult, ['items' => [['conversation_id' => 1]], 'totalRecords' => 1]);

        $data = $provider->getData();

        $this->assertSame(['conversations' => 3, 'user_messages' => 9, 'avg_latency_ms' => 500, 'total_tokens' => 100, 'total_cost' => 0.5], $data['aichat_totals']);
        $this->assertSame(['conversation_id' => 1], $data['items'][0]);
    }

    public function testGetFilteredConversationIdSelectResetsLimitOrderAndColumns(): void
    {
        // clone $searchResult->getSelect() in the production code produces a
        // new object that shares this mock's configured expectations, so
        // asserting on $select's own expects() below verifies calls made on
        // the clone that actually reaches Reports.
        $select = $this->createMock(Select::class);
        $select->method('reset')->willReturnSelf();
        $select->expects($this->exactly(4))->method('reset')->withConsecutive(
            [Select::LIMIT_COUNT],
            [Select::LIMIT_OFFSET],
            [Select::ORDER],
            [Select::COLUMNS]
        );
        $select->expects($this->once())->method('columns')->with('main_table.conversation_id');

        $searchResult = $this->getMockBuilder(SearchResult::class)->disableOriginalConstructor()->getMock();
        $searchResult->method('getSelect')->willReturn($select);

        $reports = $this->createMock(Reports::class);
        $reports->method('getTotalsForConversations')->with($this->isInstanceOf(Select::class))->willReturn(
            ['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]
        );

        $provider = $this->makeProvider($reports, $searchResult, ['items' => [], 'totalRecords' => 0]);

        $provider->getData();
    }

    /**
     * @param SearchResult&\PHPUnit\Framework\MockObject\MockObject $searchResult
     */
    private function makeProvider(Reports $reports, $searchResult, array $searchResultToOutput): ConversationDataProvider
    {
        $provider = $this->getMockBuilder(ConversationDataProvider::class)
            ->setConstructorArgs([
                'aichat_conversation_listing_data_source',
                'conversation_id',
                'conversation_id',
                $this->createMock(ReportingInterface::class),
                $this->createMock(SearchCriteriaBuilder::class),
                $this->createMock(RequestInterface::class),
                $this->createMock(FilterBuilder::class),
                $reports,
            ])
            ->onlyMethods(['getSearchResult', 'searchResultToOutput'])
            ->getMock();
        $provider->method('getSearchResult')->willReturn($searchResult);
        $provider->method('searchResultToOutput')->with($searchResult)->willReturn($searchResultToOutput);
        return $provider;
    }
}
