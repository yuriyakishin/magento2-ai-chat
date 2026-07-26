<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Conversation extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('yu_aichat_conversation', 'conversation_id');
    }
}
