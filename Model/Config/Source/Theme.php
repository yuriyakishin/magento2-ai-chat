<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Yu\AiChat\Model\ThemePalette;

class Theme implements OptionSourceInterface
{
    public function __construct(
        private readonly ThemePalette $themePalette
    ) {
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->themePalette->getThemeLabels() as $code => $label) {
            $options[] = ['value' => $code, 'label' => __($label)];
        }
        $options[] = ['value' => ThemePalette::THEME_CUSTOM, 'label' => __('Custom')];
        return $options;
    }
}
