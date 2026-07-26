<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Framework\Model\AbstractModel;

class SystemTask extends AbstractModel
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel\SystemTask::class);
    }
}
