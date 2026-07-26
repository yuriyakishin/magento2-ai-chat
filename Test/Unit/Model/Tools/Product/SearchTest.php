<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Config;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterface;
use Yu\AiSearchEngine\Api\Data\QueryOptionsInterfaceFactory;
use Yu\AiSearchEngine\Model\AttributeWhitelist;
use Yu\AiSearchEngine\Model\EngineFinder;
use Yu\AiSearchEngine\Model\QueryOptions;
use Yu\AiChat\Model\Tools\Product\Search;
use Yu\AiChat\Model\Tools\ToolContext;

class SearchTest extends TestCase
{
    private const WHITELIST = [
        'name' => ['es_field' => 'name_search', 'weight' => 5, 'input' => 'text', 'label' => 'Name'],
        'color' => ['es_field' => 'color_value', 'weight' => 3, 'input' => 'select', 'label' => 'Color'],
    ];

    public function testExecuteReturnsErrorWhenNoQueryOrValidTerms(): void
    {
        [$search] = $this->makeSearch();

        $result = $search->execute([], $this->makeContext());

        $this->assertSame(['error' => 'Provide "query" or at least one valid "terms" entry'], $result);
    }

    public function testExecuteUsesFindByQueryWhenNoTermsGiven(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->expects($this->once())->method('findByQuery')
            ->with('red shoes', ['name_search' => 5, 'color_value' => 3])
            ->willReturn([]);
        $engineFinder->expects($this->never())->method('findByTerms');

        $search->execute(['query' => 'red shoes'], $this->makeContext());
    }

    public function testExecuteUsesFindByTermsWhenTermsGiven(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->expects($this->once())->method('findByTerms')->willReturn([]);
        $engineFinder->expects($this->never())->method('findByQuery');

        $search->execute(['terms' => [['attribute' => 'color', 'query' => 'red']]], $this->makeContext());
    }

    public function testExecuteTruncatesQueryToMaxLength(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $longQuery = str_repeat('a', 300);
        $engineFinder->method('findByQuery')->willReturnCallback(
            function (string $query) use ($longQuery): array {
                $this->assertSame(200, mb_strlen($query));
                $this->assertSame(mb_substr($longQuery, 0, 200), $query);
                return [];
            }
        );

        $search->execute(['query' => $longQuery], $this->makeContext());
    }

    public function testExecuteClampsLimitToMaxResults(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $captured = null;
        $engineFinder->method('findByQuery')->willReturnCallback(
            function (string $q, array $b, QueryOptionsInterface $options) use (&$captured): array {
                $captured = $options;
                return [];
            }
        );

        $search->execute(['query' => 'x', 'limit' => 999], $this->makeContext());

        $this->assertSame(10, $captured->getLimit());
    }

    public function testExecuteDefaultsLimitWhenNotProvided(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $captured = null;
        $engineFinder->method('findByQuery')->willReturnCallback(
            function (string $q, array $b, QueryOptionsInterface $options) use (&$captured): array {
                $captured = $options;
                return [];
            }
        );

        $search->execute(['query' => 'x'], $this->makeContext());

        $this->assertSame(5, $captured->getLimit());
    }

    public function testExecuteIgnoresUnrecognizedSortValue(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $captured = null;
        $engineFinder->method('findByQuery')->willReturnCallback(
            function (string $q, array $b, QueryOptionsInterface $options) use (&$captured): array {
                $captured = $options;
                return [];
            }
        );

        $search->execute(['query' => 'x', 'sort' => 'made_up_sort'], $this->makeContext());

        $this->assertSame(QueryOptionsInterface::SORT_RELEVANCE, $captured->getSort());
    }

    public function testExecuteReturnsUnavailableErrorWhenEngineFailsAndLikeFallbackDisabled(): void
    {
        [$search, $engineFinder, , , $config, , $logger] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willThrowException(new \RuntimeException('ES down'));
        $config->method('isLikeFallbackEnabled')->willReturn(false);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('ES down'));

        $result = $search->execute(['query' => 'shoes'], $this->makeContext());

        $this->assertSame(
            'Product search is temporarily unavailable. Apologize and suggest trying again shortly.',
            $result['error']
        );
    }

    public function testExecuteFallsBackToLikeSearchWhenEngineFailsAndFallbackEnabled(): void
    {
        [$search, $engineFinder, $collectionFactory, , $config] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willThrowException(new \RuntimeException('ES down'));
        $config->method('isLikeFallbackEnabled')->willReturn(true);
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([]));

        $result = $search->execute(['query' => 'shoes'], $this->makeContext());

        $this->assertSame('shoes', $result['query']);
        $this->assertSame(0, $result['count']);
    }

    public function testExecuteReturnsNoProductsMessageWhenEngineFindsNoIds(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willReturn([]);

        $result = $search->execute(['query' => 'nonexistent'], $this->makeContext());

        $this->assertSame(['query' => 'nonexistent', 'count' => 0, 'products' => [], 'message' => 'No products found'], $result);
    }

    public function testExecuteReturnsProductsInEngineOrderRespectingLimit(): void
    {
        [$search, $engineFinder, $collectionFactory, $priceCurrency] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willReturn([30, 10, 20]);
        $priceCurrency->method('format')->willReturnCallback(static fn(float $p): string => '$' . $p);

        $p10 = $this->makeProduct(10, 'Product 10', 'SKU10');
        $p20 = $this->makeProduct(20, 'Product 20', 'SKU20');
        $p30 = $this->makeProduct(30, 'Product 30', 'SKU30');
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([$p10, $p20, $p30]));

        $result = $search->execute(['query' => 'x', 'limit' => 2], $this->makeContext());

        $this->assertSame(2, $result['count']);
        $this->assertSame(['SKU30', 'SKU10'], array_column($result['products'], 'sku'));
    }

    public function testValidateTermsDropsAttributesNotInWhitelist(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->expects($this->once())->method('findByTerms')->with(
            $this->callback(static fn(array $terms): bool => count($terms) === 1 && $terms[0]['code'] === 'color')
        )->willReturn([]);

        $search->execute(['terms' => [
            ['attribute' => 'not_whitelisted', 'query' => 'x'],
            ['attribute' => 'color', 'query' => 'red'],
        ]], $this->makeContext());
    }

    public function testValidateTermsClampsBoostWithinAllowedRange(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->expects($this->once())->method('findByTerms')->with(
            $this->callback(static fn(array $terms): bool => $terms[0]['boost'] === 10.0)
        )->willReturn([]);

        $search->execute(['terms' => [['attribute' => 'color', 'query' => 'red', 'boost' => 999]]], $this->makeContext());
    }

    public function testValidateTermsCapsAtMaxFiveTerms(): void
    {
        [$search, $engineFinder] = $this->makeSearch();
        $engineFinder->expects($this->once())->method('findByTerms')->with(
            $this->callback(static fn(array $terms): bool => count($terms) === 5)
        )->willReturn([]);

        $terms = [];
        for ($i = 0; $i < 8; $i++) {
            $terms[] = ['attribute' => 'color', 'query' => "color-$i"];
        }
        $search->execute(['terms' => $terms], $this->makeContext());
    }

    public function testFallbackQueryExcludesNegativeTermsFromLikeSearchAndLabel(): void
    {
        [$search, $engineFinder, $collectionFactory, , $config] = $this->makeSearch();
        $engineFinder->method('findByTerms')->willThrowException(new \RuntimeException('ES down'));
        $config->method('isLikeFallbackEnabled')->willReturn(true);
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([]));

        $result = $search->execute(['terms' => [
            ['attribute' => 'color', 'query' => 'red', 'exclude' => false],
            ['attribute' => 'name', 'query' => 'blue', 'exclude' => true],
        ]], $this->makeContext());

        $this->assertSame('red', $result['query']);
    }

    public function testResolveSwatchParamsOnlyUsesPositiveSelectTermsWithResolvableOption(): void
    {
        [$search, $engineFinder, $collectionFactory, , , $eavConfig] = $this->makeSearch();
        $engineFinder->method('findByTerms')->willReturn([1]);

        $source = $this->createMock(AbstractSource::class);
        $source->method('getOptionId')->with('red')->willReturn('60');
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getSource')->willReturn($source);
        $eavConfig->method('getAttribute')->with(Product::ENTITY, 'color')->willReturn($attribute);

        $product = $this->makeProduct(1, 'Configurable Shirt', 'SHIRT-1', typeId: 'configurable');
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([$product]));

        $result = $search->execute(['terms' => [['attribute' => 'color', 'query' => 'red']]], $this->makeContext());

        $this->assertStringContainsString('#color=60', $result['products'][0]['url']);
    }

    public function testResolveSwatchParamsSkipsExcludedTerms(): void
    {
        [$search, $engineFinder, $collectionFactory, , , $eavConfig] = $this->makeSearch();
        $engineFinder->method('findByTerms')->willReturn([1]);
        $eavConfig->expects($this->never())->method('getAttribute');

        $product = $this->makeProduct(1, 'Configurable Shirt', 'SHIRT-1', typeId: 'configurable');
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([$product]));

        $result = $search->execute(['terms' => [['attribute' => 'color', 'query' => 'red', 'exclude' => true]]], $this->makeContext());

        $this->assertStringNotContainsString('#', $result['products'][0]['url']);
    }

    public function testBuildProductRowOmitsSwatchHashForSimpleProducts(): void
    {
        [$search, $engineFinder, $collectionFactory, , , $eavConfig] = $this->makeSearch();
        $engineFinder->method('findByTerms')->willReturn([1]);

        $source = $this->createMock(AbstractSource::class);
        $source->method('getOptionId')->willReturn('60');
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getSource')->willReturn($source);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $product = $this->makeProduct(1, 'Simple Shirt', 'SHIRT-1', typeId: 'simple');
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([$product]));

        $result = $search->execute(['terms' => [['attribute' => 'color', 'query' => 'red']]], $this->makeContext());

        $this->assertStringNotContainsString('#', $result['products'][0]['url']);
    }

    public function testSearchLikeMatchesPluralWordAgainstSingularProductName(): void
    {
        [$search, $engineFinder, $collectionFactory, , $config] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willThrowException(new \RuntimeException('down'));
        $config->method('isLikeFallbackEnabled')->willReturn(true);
        $collection = $this->makeCollectionMock([]);
        $captured = [];
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($conditions) use (&$captured, $collection) {
                $captured[] = $conditions;
                return $collection;
            }
        );
        $collectionFactory->method('create')->willReturn($collection);

        $search->execute(['query' => 'bags'], $this->makeContext());

        // addAttributeToFilter is called for 'status' then 'visibility'
        // (both string-keyed, not condition arrays) before the per-word
        // condition group for "bags" arrives as the third call.
        $wordConditions = $captured[2];
        $likes = array_column($wordConditions, 'like');
        $this->assertContains('%bags%', $likes);
        $this->assertContains('%bag%', $likes);
    }

    public function testSearchLikeDropsWordsShorterThanTwoCharactersAndCapsAtSixWords(): void
    {
        [$search, $engineFinder, $collectionFactory, , $config] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willThrowException(new \RuntimeException('down'));
        $config->method('isLikeFallbackEnabled')->willReturn(true);
        $collection = $this->makeCollectionMock([]);
        $captured = [];
        $collection->method('addAttributeToFilter')->willReturnCallback(
            function ($conditions) use (&$captured, $collection) {
                $captured[] = $conditions;
                return $collection;
            }
        );
        $collectionFactory->method('create')->willReturn($collection);

        $search->execute(['query' => 'a one two three four five six seven'], $this->makeContext());

        // 'status' + 'visibility' baseline filters, plus up to 6 word-condition
        // groups (the short word "a" and anything past the 6-word cap are dropped).
        $this->assertLessThanOrEqual(8, count($captured));
    }

    public function testSearchLikeFiltersOutOfPriceRangeResultsAfterLoading(): void
    {
        [$search, $engineFinder, $collectionFactory, , $config] = $this->makeSearch();
        $engineFinder->method('findByQuery')->willThrowException(new \RuntimeException('down'));
        $config->method('isLikeFallbackEnabled')->willReturn(true);

        $cheap = $this->makeProduct(1, 'Cheap Shoes', 'SKU-CHEAP', price: 10.0);
        $expensive = $this->makeProduct(2, 'Expensive Shoes', 'SKU-EXPENSIVE', price: 500.0);
        $collectionFactory->method('create')->willReturn($this->makeCollectionMock([$cheap, $expensive]));

        $result = $search->execute(['query' => 'shoes', 'price_min' => 50, 'price_max' => 1000], $this->makeContext());

        $this->assertSame(['SKU-EXPENSIVE'], array_column($result['products'], 'sku'));
    }

    /**
     * @return array{0: Search, 1: EngineFinder&\PHPUnit\Framework\MockObject\MockObject,
     *     2: CollectionFactory&\PHPUnit\Framework\MockObject\MockObject,
     *     3: PriceCurrencyInterface&\PHPUnit\Framework\MockObject\MockObject,
     *     4: Config&\PHPUnit\Framework\MockObject\MockObject,
     *     5: EavConfig&\PHPUnit\Framework\MockObject\MockObject,
     *     6: LoggerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeSearch(): array
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(static fn(float $p): string => '$' . $p);
        $attributeWhitelist = $this->createMock(AttributeWhitelist::class);
        $attributeWhitelist->method('getAttributes')->willReturn(self::WHITELIST);
        $attributeWhitelist->method('getFieldBoosts')->willReturn(['name_search' => 5, 'color_value' => 3]);
        $engineFinder = $this->createMock(EngineFinder::class);
        $config = $this->createMock(Config::class);
        $eavConfig = $this->createMock(EavConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $queryOptionsFactory = $this->createMock(QueryOptionsInterfaceFactory::class);
        $queryOptionsFactory->method('create')->willReturnCallback(
            static fn (array $data = []): QueryOptions => new QueryOptions(
                $data['storeId'] ?? 0,
                $data['customerGroupId'] ?? 0,
                $data['websiteId'] ?? 0,
                $data['limit'] ?? 10,
                $data['priceMin'] ?? null,
                $data['priceMax'] ?? null,
                $data['categoryId'] ?? null,
                $data['inStockOnly'] ?? false,
                $data['sort'] ?? QueryOptions::SORT_RELEVANCE
            )
        );

        $search = new Search(
            $collectionFactory,
            $priceCurrency,
            $attributeWhitelist,
            $engineFinder,
            $config,
            $eavConfig,
            $logger,
            $queryOptionsFactory
        );

        return [$search, $engineFinder, $collectionFactory, $priceCurrency, $config, $eavConfig, $logger];
    }

    private function makeContext(): ToolContext
    {
        return new ToolContext(1, 'en_US', 'USD', null, null);
    }

    private function makeProduct(int $id, string $name, string $sku, float $price = 25.0, string $typeId = 'simple'): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->getMock();
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getSku')->willReturn($sku);
        $product->method('getData')->willReturnMap([
            ['final_price', null, $price],
            ['price', null, $price],
            ['short_description', null, 'A short description'],
        ]);
        $product->method('getProductUrl')->willReturn('https://example.test/' . $sku . '.html');
        $product->method('isSalable')->willReturn(true);
        $product->method('getTypeId')->willReturn($typeId);
        return $product;
    }

    /**
     * @param Product[] $products
     * @return Collection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeCollectionMock(array $products)
    {
        $collection = $this->getMockBuilder(Collection::class)->disableOriginalConstructor()->getMock();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addFinalPrice')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));
        return $collection;
    }
}
