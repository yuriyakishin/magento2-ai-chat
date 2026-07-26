<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\ResourceModel\Conversation;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yu\AiChat\Model\Conversation as ConversationModel;
use Yu\AiChat\Model\ResourceModel\Conversation as ConversationResource;

class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ConversationModel::class, ConversationResource::class);
    }
}
