<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Strict hex validation doubles as the safety gate: saved values are later
 * printed into an inline style tag, so nothing but #rgb / #rrggbb may pass.
 */
class HexColor extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            throw new LocalizedException(
                __('"%1" is not a valid color. Use hex format: #rgb or #rrggbb.', $value)
            );
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
