<?php
declare(strict_types=1);

namespace Yu\AiChat\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Widget\Button;

class BackButton extends Button
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setData('label', __('Back'));
        $this->setData('class', 'back');
        $this->setData('onclick', 'setLocation(\'' . $this->getUrl('aichat/conversation/index') . '\')');
    }
}
