<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Psr\Log\LoggerInterface;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Model\Config;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterfaceFactory;
use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\EngineFinder;
use Yu\AiChat\Api\Data\ToolContextInterface;

/**
 * Catalog search tool. Finding and filtering run only in the search
 * engine (constant-cost SQL afterwards: the found IDs are loaded by
 * primary key — the engine index has no clean display name for
 * configurable products, and displayed price/stock must be live). The
 * pre-engine LIKE implementation survives as a config-gated fallback:
 * it table-scans, so large catalogs disable it.
 */
class Search implements ToolInterface
{
    private const DEFAULT_RESULTS = 5;
    private const MAX_RESULTS = 10;
    private const MAX_TERMS = 5;
    private const MAX_QUERY_LENGTH = 200;
    private const BOOST_MIN = 0.1;
    private const BOOST_MAX = 10.0;
    private const SCAN_LIMIT = 20;
    private const MAX_WORDS = 6;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly AttributeWhitelist $attributeWhitelist,
        private readonly EngineFinder $engineFinder,
        private readonly Config $config,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly QueryOptionsInterfaceFactory $queryOptionsFactory
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'search_products';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Search the store catalog. Call this whenever the customer asks about products, '
            . 'availability, prices or recommendations — do not answer product questions from memory. '
            . 'Two modes: plain "query" with short keywords, or "terms" targeting specific attributes '
            . 'when the customer names properties (color, material, brand, ...). '
            . 'Supports category filter, in-stock filter and price sorting. '
            . 'Returns matching products with price, availability and URL.';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keyword search across all searchable attributes, e.g. "duffle bag". Provide query or terms.',
                ],
                'terms' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_TERMS,
                    'description' => 'Targeted search: each item matches text inside one attribute, and ALL items must match (AND). '
                        . 'Prefer this when the customer names specific properties, e.g. '
                        . '[{"attribute":"name","query":"yoga pants"},{"attribute":"color","query":"red"}] returns only red yoga pants. '
                        . 'Set exclude=true to reject matches: [{"attribute":"color","query":"red","exclude":true}] means NOT red. '
                        . 'If a specific combination returns nothing, retry with fewer terms or a plain query.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attribute' => [
                                'type' => 'string',
                                'enum' => array_keys($this->attributeWhitelist->getAttributes()),
                                'description' => $this->attributeLegend(),
                            ],
                            'query' => [
                                'type' => 'string',
                                'description' => 'Text to match in this attribute, e.g. "red" for color',
                            ],
                            'boost' => [
                                'type' => 'number',
                                'description' => 'Optional relevance weight 0.1-10; defaults to the attribute search weight',
                            ],
                            'exclude' => [
                                'type' => 'boolean',
                                'description' => 'Exclude matches instead of requiring them ("not red", "no hood")',
                            ],
                        ],
                        'required' => ['attribute', 'query'],
                    ],
                ],
                'price_min' => ['type' => 'number', 'description' => 'Minimum price filter'],
                'price_max' => ['type' => 'number', 'description' => 'Maximum price filter'],
                'category_id' => [
                    'type' => 'integer',
                    'description' => 'Restrict results to one category ID. Use the current category from page context when the customer says "here" / "in this category".',
                ],
                'in_stock_only' => [
                    'type' => 'boolean',
                    'description' => 'Only products currently in stock',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => QueryOptionsInterface::SORTS,
                    'description' => 'relevance (default), price_asc for "cheapest", price_desc for "most expensive"',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max results, 1-10, default 5'],
            ],
        ];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        $query = mb_substr(trim((string)($args['query'] ?? '')), 0, self::MAX_QUERY_LENGTH);
        $terms = $this->validateTerms(is_array($args['terms'] ?? null) ? $args['terms'] : []);
        if ($query === '' && $terms === []) {
            return ['error' => 'Provide "query" or at least one valid "terms" entry'];
        }

        $sort = (string)($args['sort'] ?? QueryOptionsInterface::SORT_RELEVANCE);
        $categoryId = (int)($args['category_id'] ?? 0);
        $options = $this->queryOptionsFactory->create([
            'storeId' => $context->getStoreId(),
            'customerGroupId' => $context->getCustomerGroupId(),
            'websiteId' => $context->getWebsiteId(),
            'limit' => max(1, min(self::MAX_RESULTS, (int)($args['limit'] ?? self::DEFAULT_RESULTS))),
            'priceMin' => isset($args['price_min']) ? (float)$args['price_min'] : null,
            'priceMax' => isset($args['price_max']) ? (float)$args['price_max'] : null,
            'categoryId' => $categoryId > 0 ? $categoryId : null,
            'inStockOnly' => (bool)($args['in_stock_only'] ?? false),
            'sort' => in_array($sort, QueryOptionsInterface::SORTS, true) ? $sort : QueryOptionsInterface::SORT_RELEVANCE,
        ]);

        try {
            $ids = $terms !== []
                ? $this->engineFinder->findByTerms($terms, $options)
                : $this->engineFinder->findByQuery($query, $this->attributeWhitelist->getFieldBoosts(), $options);
        } catch (\Throwable $e) {
            $this->logger->warning('search_products engine path failed: ' . $e->getMessage());
            if (!$this->config->isLikeFallbackEnabled()) {
                return ['error' => 'Product search is temporarily unavailable. Apologize and suggest trying again shortly.'];
            }
            // Degraded mode: category/stock/sort are engine-only features.
            return $this->searchLike($this->fallbackQuery($query, $terms), $options->getPriceMin(), $options->getPriceMax(), $context);
        }

        $label = $query !== '' ? $query : $this->fallbackQuery($query, $terms);
        $products = $this->loadByIds($ids, $options->getLimit(), $context, $this->resolveSwatchParams($terms));
        if ($products === []) {
            return ['query' => $label, 'count' => 0, 'products' => [], 'message' => 'No products found'];
        }
        return ['query' => $label, 'count' => count($products), 'products' => $products];
    }

    /**
     * Untrusted model output -> validated engine terms. Unknown attributes
     * are dropped, boost clamped, count capped.
     *
     * @param array $rawTerms
     * @return array<int, array{field: string, query: string, boost: float, exclude: bool}>
     */
    private function validateTerms(array $rawTerms): array
    {
        $whitelist = $this->attributeWhitelist->getAttributes();
        $terms = [];
        foreach (array_slice($rawTerms, 0, self::MAX_TERMS) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $code = (string)($raw['attribute'] ?? '');
            $text = mb_substr(trim((string)($raw['query'] ?? '')), 0, self::MAX_QUERY_LENGTH);
            if ($text === '' || !isset($whitelist[$code])) {
                continue;
            }
            $boost = isset($raw['boost']) ? (float)$raw['boost'] : (float)$whitelist[$code]['weight'];
            $terms[] = [
                'field' => $whitelist[$code]['es_field'],
                'code' => $code,
                'query' => $text,
                'boost' => min(self::BOOST_MAX, max(self::BOOST_MIN, $boost)),
                'exclude' => (bool)($raw['exclude'] ?? false),
            ];
        }
        return $terms;
    }

    /**
     * The LIKE fallback and the result label need plain text even when the
     * model sent only terms. Excluded terms must not leak in: LIKE would
     * REQUIRE the very words the model asked to avoid.
     *
     * @param string $query
     * @param array $terms
     * @return string
     */
    private function fallbackQuery(string $query, array $terms): string
    {
        if ($query !== '') {
            return $query;
        }
        $positive = array_filter($terms, static fn(array $t): bool => !$t['exclude']);
        return trim(implode(' ', array_column($positive, 'query')));
    }

    /**
     * Deep-link params for swatch preselection: the storefront
     * swatch-renderer reads "#code=optionId" pairs from the URL and clicks
     * the matching swatch, so a "red" search can land on the red variant.
     * Only positive select/multiselect terms whose text resolves to a real
     * option contribute.
     *
     * @param array<int, array{field: string, code: string, query: string, boost: float, exclude: bool}> $terms
     * @return array<string, int> attribute code => option id
     */
    private function resolveSwatchParams(array $terms): array
    {
        $whitelist = $this->attributeWhitelist->getAttributes();
        $params = [];
        foreach ($terms as $term) {
            $input = $whitelist[$term['code']]['input'] ?? '';
            if ($term['exclude'] || !in_array($input, ['select', 'multiselect'], true)) {
                continue;
            }
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $term['code']);
                $optionId = $attribute->getSource()->getOptionId($term['query']);
            } catch (\Throwable $e) {
                continue;
            }
            if ($optionId !== null && $optionId !== '') {
                $params[$term['code']] = (int)$optionId;
            }
        }
        return $params;
    }

    /**
     * Constant-cost hydration: primary-key load of the winners, engine
     * result order preserved.
     *
     * @param int[] $ids
     * @param int $limit
     * @param ToolContextInterface $context
     * @param array<string, int> $swatchParams
     * @return array<int, array<string, mixed>>
     */
    private function loadByIds(array $ids, int $limit, ToolContextInterface $context, array $swatchParams = []): array
    {
        if ($ids === []) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($context->getStoreId());
        $collection->addAttributeToSelect(['name', 'short_description']);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addIdFilter($ids);
        $collection->addFinalPrice();
        $collection->setPageSize(count($ids));

        $byId = [];
        foreach ($collection as $product) {
            $byId[(int)$product->getId()] = $product;
        }
        $products = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $products[] = $this->buildProductRow($byId[$id], $context, $swatchParams);
            if (count($products) >= $limit) {
                break;
            }
        }
        return $products;
    }

    /**
     * @param Product $product
     * @param ToolContextInterface $context
     * @param array<string, int> $swatchParams
     * @return array<string, mixed>
     */
    private function buildProductRow(Product $product, ToolContextInterface $context, array $swatchParams = []): array
    {
        $price = (float)($product->getData('final_price') ?: $product->getData('price'));
        $url = (string)$product->getProductUrl();
        // Preselect the searched variant on configurables; the hash is
        // client-side only, so FPC sees one URL per product.
        if ($swatchParams !== [] && $product->getTypeId() === 'configurable') {
            $url .= '#' . http_build_query($swatchParams);
        }
        return [
            'name' => (string)$product->getName(),
            'sku' => (string)$product->getSku(),
            'price' => $this->priceCurrency->format($price, false),
            'price_value' => round($price, 2),
            'currency' => $context->getCurrencyCode(),
            'in_stock' => (bool)$product->isSalable(),
            'url' => $url,
            'description' => mb_substr(trim(strip_tags((string)$product->getData('short_description'))), 0, 200),
        ];
    }

    /**
     * @return string
     */
    private function attributeLegend(): string
    {
        $parts = [];
        foreach ($this->attributeWhitelist->getAttributes() as $code => $meta) {
            $hint = match ($meta['input']) {
                'select', 'multiselect' => 'match option label',
                'boolean' => 'use "Yes"/"No"',
                default => 'free text',
            };
            $parts[] = $code . ' (' . $hint . ')';
        }
        return 'Attribute to search in: ' . implode(', ', $parts);
    }

    /**
     * Pre-engine implementation, kept verbatim as the config-gated
     * fallback. Deterministic LIKE over name/sku: every query word (with a
     * naive singular fallback) must match one of the fields. Table-scans —
     * only acceptable for small catalogs.
     *
     * @param string $query
     * @param float|null $priceMin
     * @param float|null $priceMax
     * @param ToolContextInterface $context
     * @return array<string, mixed>
     */
    private function searchLike(string $query, ?float $priceMin, ?float $priceMax, ToolContextInterface $context): array
    {
        if ($query === '') {
            return ['error' => 'Provide "query" or at least one valid "terms" entry'];
        }
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($context->getStoreId());
        $collection->addAttributeToSelect(['name', 'short_description']);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]]);
        foreach ($this->queryWords($query) as $word) {
            $conditions = [];
            foreach ($this->wordVariants($word) as $variant) {
                $like = '%' . $variant . '%';
                // short_description is deliberately NOT here: an OR-group
                // join on a mostly-empty EAV attribute excludes products
                // that have no value row at all.
                $conditions[] = ['attribute' => 'name', 'like' => $like];
                $conditions[] = ['attribute' => 'sku', 'like' => $like];
            }
            // OR across fields/variants, AND across words.
            $collection->addAttributeToFilter($conditions);
        }
        $collection->addFinalPrice();
        $collection->setPageSize(self::SCAN_LIMIT);

        $products = [];
        foreach ($collection as $product) {
            $price = (float)($product->getData('final_price') ?: $product->getData('price'));
            if (($priceMin !== null && $price < $priceMin) || ($priceMax !== null && $price > $priceMax)) {
                continue;
            }
            $products[] = $this->buildProductRow($product, $context);
            if (count($products) >= self::DEFAULT_RESULTS) {
                break;
            }
        }

        if ($products === []) {
            return ['query' => $query, 'count' => 0, 'products' => [], 'message' => 'No products found'];
        }
        return ['query' => $query, 'count' => count($products), 'products' => $products];
    }

    /**
     * @param string $query
     * @return string[]
     */
    private function queryWords(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 2);
        return array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * "bags" must still match "Bag": try the word and its naive singular.
     *
     * @param string $word
     * @return string[]
     */
    private function wordVariants(string $word): array
    {
        $variants = [$word];
        if (mb_strlen($word) > 3 && str_ends_with($word, 's')) {
            $variants[] = mb_substr($word, 0, -1);
        }
        return $variants;
    }
}
