<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\ResourceModel\Message;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Yu\AiChat\Model\Message as MessageModel;
use Yu\AiChat\Model\ResourceModel\Message as MessageResource;

class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(MessageModel::class, MessageResource::class);
    }
}
