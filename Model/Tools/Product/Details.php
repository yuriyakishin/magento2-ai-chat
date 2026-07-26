<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;

class Details implements ToolInterface
{
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
        return 'get_product';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Get full details of one specific product by SKU or ID: description, attributes '
            . '(color, size, material, ...), exact price, availability and URL. Call it for detail '
            . 'questions ("is this waterproof?"). For comparing two or more named products, use '
            . 'compare_products instead of calling this once per product.';
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

        return $this->formatter->format($product, $context);
    }
}
