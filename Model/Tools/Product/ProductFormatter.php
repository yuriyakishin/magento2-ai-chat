<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;

/**
 * The product card shape every product-facing tool (get_product,
 * compare_products, get_related_products) hands to the model. Single
 * source of truth so the fields the model relies on can't drift between
 * tools that happen to look at the same product.
 */
class ProductFormatter
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ConfigurableResource $configurableResource,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @param Product $product
     * @param ToolContextInterface $context
     * @return array<string, mixed>
     */
    public function format(Product $product, ToolContextInterface $context): array
    {
        $regular = (float)$product->getPrice();
        $final = (float)$product->getFinalPrice();

        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsVisibleOnFront()) {
                continue;
            }
            $value = $attribute->getFrontend()->getValue($product);
            if (is_string($value) && trim($value) !== '' && $value !== 'N/A' && $value !== 'No') {
                $attributes[(string)$attribute->getStoreLabel()] = $value;
            }
        }

        $result = [
            'name' => (string)$product->getName(),
            'sku' => (string)$product->getSku(),
            'price' => $this->priceCurrency->format($final, false),
            'price_value' => round($final, 2),
            'currency' => $context->getCurrencyCode(),
            'in_stock' => (bool)$product->isSalable(),
            'description' => mb_substr(trim(strip_tags((string)$product->getData('description'))), 0, 500),
            'attributes' => $attributes,
        ];
        $url = $this->resolveUrl($product, $context);
        if ($url !== null) {
            $result['url'] = $url;
        }
        if ($final < $regular) {
            $result['regular_price'] = $this->priceCurrency->format($regular, false);
            $result['on_sale'] = true;
        }
        return $result;
    }

    /**
     * A product set to "Not Visible Individually" (typically a color/size
     * child of a configurable) has no real storefront page - its own
     * getProductUrl() 404s. Link to a viewable parent instead; omit the
     * field entirely rather than hand the model a dead link when none exists.
     *
     * @param Product $product
     * @param ToolContextInterface $context
     * @return string|null
     */
    private function resolveUrl(Product $product, ToolContextInterface $context): ?string
    {
        if ((int)$product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
            return (string)$product->getProductUrl();
        }
        foreach ($this->configurableResource->getParentIdsByChild($product->getId()) as $parentId) {
            try {
                $parent = $this->productRepository->getById((int)$parentId, false, $context->getStoreId());
            } catch (NoSuchEntityException $e) {
                continue;
            }
            if ((int)$parent->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
                return (string)$parent->getProductUrl();
            }
        }
        return null;
    }
}
