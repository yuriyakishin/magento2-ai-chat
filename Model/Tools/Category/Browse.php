<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Exception\NoSuchEntityException;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Model\Tools\Product\ProductFormatter;
use Yu\AiChat\Api\Data\ToolContextInterface;

class Browse implements ToolInterface
{
    private const MAX_RESULTS = 10;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFormatter $formatter
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'browse_category';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Browse a specific category: its subcategories and a page of its products. Use it for '
            . 'catalog navigation ("what categories do you have?", "show me your bags", "what\'s in the '
            . 'Sale category?") with category_id from page context or a previous tool result. For '
            . 'keyword-driven search use search_products instead.';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category_id' => ['type' => 'integer', 'description' => 'Category ID to browse'],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max products to return, 1-' . self::MAX_RESULTS . ', default ' . self::MAX_RESULTS,
                ],
            ],
            'required' => ['category_id'],
        ];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        $categoryId = (int)($args['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return ['error' => 'Provide category_id'];
        }

        try {
            $category = $this->categoryRepository->get($categoryId, $context->getStoreId());
        } catch (NoSuchEntityException $e) {
            return ['error' => 'Category not found'];
        }
        if (!$category->getIsActive()) {
            return ['error' => 'Category not found'];
        }

        $limit = max(1, min(self::MAX_RESULTS, (int)($args['limit'] ?? self::MAX_RESULTS)));

        $subcategories = [];
        foreach ($category->getChildrenCategories() as $child) {
            if ($child->getIsActive()) {
                $subcategories[] = ['id' => (int)$child->getId(), 'name' => (string)$child->getName()];
            }
        }

        $productCollection = $category->getProductCollection();
        $productCollection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $productCollection->setPageSize($limit);

        $products = [];
        foreach ($productCollection as $item) {
            try {
                $full = $this->productRepository->getById((int)$item->getId(), false, $context->getStoreId());
            } catch (NoSuchEntityException $e) {
                continue;
            }
            $formatted = $this->formatter->format($full, $context);
            if (!isset($formatted['url'])) {
                // Nothing the customer could actually click through to buy.
                continue;
            }
            $products[] = $formatted;
        }

        return [
            'category_id' => $categoryId,
            'category_name' => (string)$category->getName(),
            'subcategories' => $subcategories,
            'products' => $products,
            'product_count' => count($products),
        ];
    }
}
