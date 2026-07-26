<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Yu\AiChat\Api\Data\PageContextInterface;

class PageContext implements PageContextInterface
{
    public function __construct(
        private readonly string $pageType,
        private readonly ?int $productId,
        private readonly ?int $categoryId,
        private readonly ?string $url,
        private readonly ?string $referrer,
        private readonly ?int $customerGroupId
    ) {
    }

    /**
     * @return string
     */
    public function getPageType(): string
    {
        return $this->pageType;
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

    /**
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @return string|null
     */
    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    /**
     * @return int|null
     */
    public function getCustomerGroupId(): ?int
    {
        return $this->customerGroupId;
    }
}
