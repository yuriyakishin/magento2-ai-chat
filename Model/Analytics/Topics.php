<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Analytics;

use Magento\Framework\Data\OptionSourceInterface;

class Topics implements OptionSourceInterface
{
    public const ALL = [
        'product_search',
        'recommendation',
        'comparison',
        'product_info',
        'shipping',
        'payment',
        'returns',
        'warranty',
        'promotions',
        'other',
    ];

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::ALL as $topic) {
            $options[] = ['value' => $topic, 'label' => ucwords(str_replace('_', ' ', $topic))];
        }
        return $options;
    }
}
