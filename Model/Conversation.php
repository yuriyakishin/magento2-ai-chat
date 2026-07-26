<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Framework\Model\AbstractModel;

class Conversation extends AbstractModel
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\Conversation::class);
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return (string)$this->getData('uuid');
    }
}
