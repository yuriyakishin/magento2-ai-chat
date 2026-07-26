<?php
declare(strict_types=1);

namespace Yu\AiChat\Api\Data;

interface ToolContextInterface
{
    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @return string
     */
    public function getLocale(): string;

    /**
     * @return string
     */
    public function getCurrencyCode(): string;

    /**
     * @return int|null
     */
    public function getProductId(): ?int;

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int;

    /**
     * @return int
     */
    public function getCustomerGroupId(): int;

    /**
     * @return int
     */
    public function getWebsiteId(): int;
}
