<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools;

use Yu\AiChat\Api\Data\ToolContextInterface;

class ToolContext implements ToolContextInterface
{
    public function __construct(
        private readonly int $storeId,
        private readonly string $locale,
        private readonly string $currencyCode,
        private readonly ?int $productId,
        private readonly ?int $categoryId,
        private readonly int $customerGroupId = 0,
        private readonly int $websiteId = 0
    ) {
    }

    /**
     * @return int
     */
    public function getCustomerGroupId(): int
    {
        return $this->customerGroupId;
    }

    /**
     * @return int
     */
    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * @return int|null
     */
    public function getProductId(): ?int
    {
        return $this->productId;
    }

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }
}
