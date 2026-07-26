<?php
declare(strict_types=1);

namespace Yu\AiChat\Api\Data;

interface PageContextInterface
{
    /**
     * @return string
     */
    public function getPageType(): string;

    /**
     * @return int|null
     */
    public function getProductId(): ?int;

    /**
     * @return int|null
     */
    public function getCategoryId(): ?int;

    /**
     * @return string|null
     */
    public function getUrl(): ?string;

    /**
     * @return string|null
     */
    public function getReferrer(): ?string;

    /**
     * @return int|null
     */
    public function getCustomerGroupId(): ?int;
}
