<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Analytics;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Every report is one SQL aggregate over the chat tables. Dashboard and
 * insights generation both read from here.
 */
class Reports
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @param string $period
     * @return array{conversations:int, user_messages:int, avg_latency_ms:int, total_tokens:int, total_cost:float}
     */
    public function getTotals(string $period): array
    {
        $conn = $this->resource->getConnection();
        $conversations = (int)$conn->fetchOne(
            $this->applyPeriod(
                $conn->select()->from($this->table('yu_aichat_conversation'), 'COUNT(*)'),
                $period
            )
        );
        $row = $conn->fetchRow(
            $this->applyPeriod(
                $conn->select()->from($this->table('yu_aichat_message'), [
                    'user_messages' => new \Zend_Db_Expr("SUM(IF(role = 'user', 1, 0))"),
                    'avg_latency_ms' => new \Zend_Db_Expr("AVG(IF(role = 'assistant' AND status = 'complete', latency_ms, NULL))"),
                    'total_tokens' => new \Zend_Db_Expr('SUM(total_tokens)'),
                    'total_cost' => new \Zend_Db_Expr('SUM(cost)'),
                ]),
                $period
            )
        ) ?: [];
        return [
            'conversations' => $conversations,
            'user_messages' => (int)($row['user_messages'] ?? 0),
            'avg_latency_ms' => (int)round((float)($row['avg_latency_ms'] ?? 0)),
            'total_tokens' => (int)($row['total_tokens'] ?? 0),
            'total_cost' => round((float)($row['total_cost'] ?? 0), 4),
        ];
    }

    /**
     * @param string $period
     * @return array<int, array<string, mixed>>
     */
    public function getCostByProvider(string $period): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_message'), [
                        'provider',
                        'model',
                        'replies' => new \Zend_Db_Expr('COUNT(*)'),
                        'tokens' => new \Zend_Db_Expr('SUM(total_tokens)'),
                        'cost' => new \Zend_Db_Expr('SUM(cost)'),
                    ])
                    ->where("role = 'assistant'")
                    ->where('provider IS NOT NULL')
                    ->group(['provider', 'model'])
                    ->order('cost DESC'),
                $period
            )
        );
    }

    /**
     * @param string $period
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getPopularQuestions(string $period, int $limit = 10): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_message'), [
                        'question' => new \Zend_Db_Expr('MIN(content)'),
                        'cnt' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where("role = 'user'")
                    ->group(new \Zend_Db_Expr('LOWER(TRIM(content))'))
                    ->order('cnt DESC')
                    ->limit($limit),
                $period
            )
        );
    }

    /**
     * Page-context based: which products customers were viewing while asking.
     *
     * @param string $period
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getPopularProducts(string $period, int $limit = 10): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_message'), [
                        'product_id',
                        'cnt' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where('product_id IS NOT NULL')
                    ->where("role = 'user'")
                    ->group('product_id')
                    ->order('cnt DESC')
                    ->limit($limit),
                $period
            )
        );
        foreach ($rows as &$row) {
            try {
                $product = $this->productRepository->getById((int)$row['product_id']);
                $row['name'] = (string)$product->getName();
                $row['sku'] = (string)$product->getSku();
            } catch (NoSuchEntityException $e) {
                $row['name'] = 'Deleted product #' . $row['product_id'];
                $row['sku'] = '';
            }
        }
        return $rows;
    }

    /**
     * Zero-result searches: what customers wanted and did not find.
     *
     * @param string $period
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getMissingProducts(string $period, int $limit = 10): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_message'), [
                        'query' => new \Zend_Db_Expr("JSON_UNQUOTE(JSON_EXTRACT(content, '$.arguments.query'))"),
                        'cnt' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where("role = 'tool'")
                    ->where('content LIKE ?', '{"tool":"search_products"%')
                    ->where(new \Zend_Db_Expr("JSON_EXTRACT(content, '$.result.count') = 0"))
                    ->group('query')
                    ->order('cnt DESC')
                    ->limit($limit),
                $period
            )
        );
    }

    /**
     * @param string $period
     * @return array<int, array<string, mixed>>
     */
    public function getTopics(string $period): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_conversation'), [
                        'topic',
                        'cnt' => new \Zend_Db_Expr('COUNT(*)'),
                    ])
                    ->where('topic IS NOT NULL')
                    ->group('topic')
                    ->order('cnt DESC'),
                $period
            )
        );
        $unclassified = (int)$conn->fetchOne(
            $this->applyPeriod(
                $conn->select()
                    ->from($this->table('yu_aichat_conversation'), 'COUNT(*)')
                    ->where('topic IS NULL'),
                $period
            )
        );
        if ($unclassified > 0) {
            $rows[] = ['topic' => 'unclassified', 'cnt' => $unclassified];
        }
        return $rows;
    }

    /**
     * Same shape as getTotals(), but scoped to an arbitrary set of
     * conversations instead of a date period - used to keep the KPI panel
     * on the Conversations grid in sync with whatever the grid's own
     * filters currently match.
     *
     * @param \Magento\Framework\DB\Select $conversationIdSelect
     * @return array{conversations:int, user_messages:int, avg_latency_ms:int, total_tokens:int, total_cost:float}
     */
    public function getTotalsForConversations(\Magento\Framework\DB\Select $conversationIdSelect): array
    {
        $conn = $this->resource->getConnection();
        $conversations = (int)$conn->fetchOne(
            $conn->select()->from(['t' => $conversationIdSelect], 'COUNT(*)')
        );
        if ($conversations === 0) {
            return [
                'conversations' => 0,
                'user_messages' => 0,
                'avg_latency_ms' => 0,
                'total_tokens' => 0,
                'total_cost' => 0.0,
            ];
        }
        $row = $conn->fetchRow(
            $conn->select()
                ->from($this->table('yu_aichat_message'), [
                    'user_messages' => new \Zend_Db_Expr("SUM(IF(role = 'user', 1, 0))"),
                    'avg_latency_ms' => new \Zend_Db_Expr("AVG(IF(role = 'assistant' AND status = 'complete', latency_ms, NULL))"),
                    'total_tokens' => new \Zend_Db_Expr('SUM(total_tokens)'),
                    'total_cost' => new \Zend_Db_Expr('SUM(cost)'),
                ])
                ->where('conversation_id IN (?)', $conversationIdSelect)
        ) ?: [];
        return [
            'conversations' => $conversations,
            'user_messages' => (int)($row['user_messages'] ?? 0),
            'avg_latency_ms' => (int)round((float)($row['avg_latency_ms'] ?? 0)),
            'total_tokens' => (int)($row['total_tokens'] ?? 0),
            'total_cost' => round((float)($row['total_cost'] ?? 0), 4),
        ];
    }

    /**
     * Most recent background/system task runs (topic classification, insight
     * generation) - an activity log, not scoped to the dashboard's date
     * period, since these crons run at most nightly/weekly.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentSystemTasks(int $limit = 20): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $conn->select()
                ->from($this->table('yu_aichat_system_task'))
                ->order('created_at DESC')
                ->limit($limit)
        );
    }

    /**
     * @param string $name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * Adds the period's date bounds to a select, filtered on created_at.
     * "today"/"yesterday" are bounded calendar days; day-count periods
     * (e.g. "30") stay open-ended (since N days ago, up to now) as before.
     *
     * @param \Magento\Framework\DB\Select $select
     * @param string $period
     * @return \Magento\Framework\DB\Select
     */
    private function applyPeriod(\Magento\Framework\DB\Select $select, string $period): \Magento\Framework\DB\Select
    {
        [$from, $to] = $this->periodBounds($period);
        $select->where('created_at >= ?', $from->format('Y-m-d H:i:s'));
        if ($to !== null) {
            $select->where('created_at < ?', $to->format('Y-m-d H:i:s'));
        }
        return $select;
    }

    /**
     * @param string $period
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable|null}
     */
    private function periodBounds(string $period): array
    {
        $now = new \DateTimeImmutable();
        switch ($period) {
            case 'today':
                return [$now->setTime(0, 0), null];
            case 'yesterday':
                $startOfToday = $now->setTime(0, 0);
                return [$startOfToday->modify('-1 day'), $startOfToday];
            default:
                return [$now->modify(sprintf('-%d days', (int)$period)), null];
        }
    }
}
