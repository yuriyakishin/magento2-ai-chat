<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Position implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'bottom-right', 'label' => __('Bottom Right')],
            ['value' => 'bottom-left', 'label' => __('Bottom Left')],
        ];
    }
}
