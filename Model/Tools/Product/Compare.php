<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;

class Compare implements ToolInterface
{
    private const MAX_PRODUCTS = 4;

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
        return 'compare_products';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Compare two or more specific products side by side by SKU: price, availability, URL and '
            . 'attributes for each. Call this instead of multiple get_product calls whenever the customer '
            . 'wants to compare named products (e.g. "compare iPhone and Galaxy", "which is better, X or Y").';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'skus' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 2,
                    'maxItems' => self::MAX_PRODUCTS,
                    'description' => 'SKUs of the products to compare, 2 to ' . self::MAX_PRODUCTS . '.',
                ],
            ],
            'required' => ['skus'],
        ];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        $skus = $this->validateSkus(is_array($args['skus'] ?? null) ? $args['skus'] : []);
        if (count($skus) < 2) {
            return ['error' => 'Provide at least two SKUs to compare'];
        }

        $products = [];
        $notFound = [];
        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku, false, $context->getStoreId());
                $products[] = $this->formatter->format($product, $context);
            } catch (NoSuchEntityException $e) {
                $notFound[] = $sku;
            }
        }

        if (count($products) < 2) {
            return ['error' => 'Could not find at least two of the given products', 'not_found' => $notFound];
        }

        $result = ['products' => $products];
        if ($notFound !== []) {
            $result['not_found'] = $notFound;
        }
        return $result;
    }

    /**
     * @param array<int, mixed> $rawSkus
     * @return string[]
     */
    private function validateSkus(array $rawSkus): array
    {
        $skus = [];
        foreach ($rawSkus as $raw) {
            $sku = trim((string)$raw);
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }
        return array_slice(array_keys($skus), 0, self::MAX_PRODUCTS);
    }
}
