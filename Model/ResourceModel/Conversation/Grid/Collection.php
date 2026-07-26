<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\ResourceModel\Conversation\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * mainTable/resourceModel are supplied via etc/di.xml type configuration,
 * the same pattern Magento core uses for every UI Component grid backed
 * by a plain (non-EAV) table.
 */
class Collection extends SearchResult
{
    /**
     * "conversation_id" is ambiguous once msg_agg is joined below (its
     * subquery also exposes conversation_id, for the JOIN condition), so
     * grid filters/sorting on the ID column need to be pointed at
     * main_table explicitly.
     */
    protected function _construct()
    {
        parent::_construct();
        $this->_map['fields']['conversation_id'] = 'main_table.conversation_id';
    }

    /**
     * Adds message_count/total_cost per conversation - cheap, useful
     * at-a-glance columns the raw conversation table does not carry.
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $connection = $this->getConnection();
        $messageAgg = $connection->select()
            ->from(
                $this->getTable('yu_aichat_message'),
                [
                    'conversation_id',
                    'message_count' => new \Zend_Db_Expr('COUNT(*)'),
                    'total_cost' => new \Zend_Db_Expr('SUM(cost)'),
                ]
            )
            ->group('conversation_id');
        $this->getSelect()->joinLeft(
            ['msg_agg' => $messageAgg],
            'msg_agg.conversation_id = main_table.conversation_id',
            ['message_count', 'total_cost']
        );
        return $this;
    }
}
