<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;

/**
 * Reads the merchant's own Related/Up-sell/Cross-sell assignments rather
 * than inventing a similarity heuristic - whatever the merchant curated
 * in the admin is exactly what the model recommends.
 */
class Related implements ToolInterface
{
    private const MAX_RESULTS = 8;
    private const TYPES = ['related', 'upsell', 'crosssell', 'any'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFormatter $formatter
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'get_related_products';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Get products related to, up-sell or cross-sell to a specific product, as configured by '
            . 'the merchant. Call it for recommendation questions ("what goes well with this?", '
            . '"recommend something similar", "what else should I get with this bag?").';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                'id' => ['type' => 'integer', 'description' => 'Product ID'],
                'type' => [
                    'type' => 'string',
                    'enum' => self::TYPES,
                    'description' => 'Which relation to return; "any" (default) merges all three, related first.',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        try {
            if (!empty($args['sku'])) {
                $product = $this->productRepository->get((string)$args['sku'], false, $context->getStoreId());
            } elseif (!empty($args['id'])) {
                $product = $this->productRepository->getById((int)$args['id'], false, $context->getStoreId());
            } else {
                return ['error' => 'Provide sku or id'];
            }
        } catch (NoSuchEntityException $e) {
            return ['error' => 'Product not found'];
        }

        $type = in_array($args['type'] ?? null, self::TYPES, true) ? (string)$args['type'] : 'any';
        $candidates = [];
        if ($type === 'related' || $type === 'any') {
            foreach ($product->getRelatedProductCollection() as $item) {
                $candidates[] = $item;
            }
        }
        if ($type === 'upsell' || $type === 'any') {
            foreach ($product->getUpSellProductCollection() as $item) {
                $candidates[] = $item;
            }
        }
        if ($type === 'crosssell' || $type === 'any') {
            foreach ($product->getCrossSellProductCollection() as $item) {
                $candidates[] = $item;
            }
        }

        $seen = [];
        $products = [];
        foreach ($candidates as $candidate) {
            $id = (int)$candidate->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $full = $this->productRepository->getById($id, false, $context->getStoreId());
            } catch (NoSuchEntityException $e) {
                continue;
            }
            $formatted = $this->formatter->format($full, $context);
            if (!isset($formatted['url'])) {
                // Nothing the customer could actually click through to buy -
                // a recommendation, unlike a named lookup, is worthless without one.
                continue;
            }
            $products[] = $formatted;
            if (count($products) >= self::MAX_RESULTS) {
                break;
            }
        }

        if ($products === []) {
            return [
                'sku' => (string)$product->getSku(),
                'products' => [],
                'message' => 'No related products configured for this item',
            ];
        }
        return ['sku' => (string)$product->getSku(), 'products' => $products];
    }
}
