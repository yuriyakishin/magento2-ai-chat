<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Tools\Product\ProductFormatter;
use Yu\AiChat\Model\Tools\ToolContext;

class ProductFormatterTest extends TestCase
{
    public function testFormatIncludesCoreFieldsAndCurrency(): void
    {
        $product = $this->makeProduct(price: 50.0, finalPrice: 50.0, visibility: Visibility::VISIBILITY_BOTH);
        $formatter = $this->makeFormatter();
        $context = $this->makeContext(currencyCode: 'USD');

        $result = $formatter->format($product, $context);

        $this->assertSame('Test Product', $result['name']);
        $this->assertSame('TEST-SKU', $result['sku']);
        $this->assertSame(50.0, $result['price_value']);
        $this->assertSame('USD', $result['currency']);
        $this->assertTrue($result['in_stock']);
    }

    public function testFormatAddsRegularPriceAndOnSaleFlagWhenDiscounted(): void
    {
        $product = $this->makeProduct(price: 100.0, finalPrice: 75.0, visibility: Visibility::VISIBILITY_BOTH);
        $formatter = $this->makeFormatter();

        $result = $formatter->format($product, $this->makeContext());

        $this->assertTrue($result['on_sale']);
        $this->assertArrayHasKey('regular_price', $result);
    }

    public function testFormatOmitsRegularPriceAndOnSaleFlagWhenNotDiscounted(): void
    {
        $product = $this->makeProduct(price: 50.0, finalPrice: 50.0, visibility: Visibility::VISIBILITY_BOTH);
        $formatter = $this->makeFormatter();

        $result = $formatter->format($product, $this->makeContext());

        $this->assertArrayNotHasKey('on_sale', $result);
        $this->assertArrayNotHasKey('regular_price', $result);
    }

    public function testFormatStripsTagsAndTruncatesDescriptionTo500Characters(): void
    {
        $product = $this->makeProduct(description: '<p>' . str_repeat('a', 600) . '</p>');
        $formatter = $this->makeFormatter();

        $result = $formatter->format($product, $this->makeContext());

        $this->assertSame(500, mb_strlen($result['description']));
        $this->assertStringNotContainsString('<p>', $result['description']);
    }

    public function testFormatIncludesUrlForAVisibleProduct(): void
    {
        $product = $this->makeProduct(visibility: Visibility::VISIBILITY_BOTH, url: 'https://example.test/product.html');
        $formatter = $this->makeFormatter();

        $result = $formatter->format($product, $this->makeContext());

        $this->assertSame('https://example.test/product.html', $result['url']);
    }

    public function testFormatOmitsUrlForNotVisibleProductWithNoViewableParent(): void
    {
        $product = $this->makeProduct(visibility: Visibility::VISIBILITY_NOT_VISIBLE, productId: 5);
        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->with(5)->willReturn([]);
        $formatter = $this->makeFormatter(configurableResource: $configurableResource);

        $result = $formatter->format($product, $this->makeContext());

        $this->assertArrayNotHasKey('url', $result);
    }

    public function testFormatUsesViewableParentUrlForNotVisibleChild(): void
    {
        $child = $this->makeProduct(visibility: Visibility::VISIBILITY_NOT_VISIBLE, productId: 5);
        $parent = $this->makeProduct(visibility: Visibility::VISIBILITY_BOTH, url: 'https://example.test/parent.html');

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->with(5)->willReturn([99]);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->with(99, false, 1)->willReturn($parent);

        $formatter = $this->makeFormatter($configurableResource, $productRepository);

        $result = $formatter->format($child, $this->makeContext());

        $this->assertSame('https://example.test/parent.html', $result['url']);
    }

    public function testFormatSkipsParentThatNoLongerExists(): void
    {
        $child = $this->makeProduct(visibility: Visibility::VISIBILITY_NOT_VISIBLE, productId: 5);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->with(5)->willReturn([99]);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));

        $formatter = $this->makeFormatter($configurableResource, $productRepository);

        $result = $formatter->format($child, $this->makeContext());

        $this->assertArrayNotHasKey('url', $result);
    }

    public function testFormatIncludesOnlyVisibleAttributesWithMeaningfulValues(): void
    {
        $visibleAttr = $this->makeAttribute('Color', 'Red', isVisible: true);
        $hiddenAttr = $this->makeAttribute('Internal Code', 'XYZ', isVisible: false);
        $naAttr = $this->makeAttribute('Material', 'N/A', isVisible: true);
        $noAttr = $this->makeAttribute('Waterproof', 'No', isVisible: true);
        $emptyAttr = $this->makeAttribute('Notes', '  ', isVisible: true);

        $product = $this->makeProduct(attributes: [$visibleAttr, $hiddenAttr, $naAttr, $noAttr, $emptyAttr]);
        $formatter = $this->makeFormatter();

        $result = $formatter->format($product, $this->makeContext());

        $this->assertSame(['Color' => 'Red'], $result['attributes']);
    }

    private function makeFormatter(
        ?ConfigurableResource $configurableResource = null,
        ?ProductRepositoryInterface $productRepository = null
    ): ProductFormatter {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn(float $amount): string => '$' . number_format($amount, 2)
        );

        return new ProductFormatter(
            $priceCurrency,
            $configurableResource ?? $this->createMock(ConfigurableResource::class),
            $productRepository ?? $this->createMock(ProductRepositoryInterface::class)
        );
    }

    private function makeContext(string $currencyCode = 'USD'): ToolContext
    {
        return new ToolContext(1, 'en_US', $currencyCode, null, null);
    }

    /**
     * @param Attribute[] $attributes
     */
    private function makeProduct(
        float $price = 50.0,
        float $finalPrice = 50.0,
        int $visibility = Visibility::VISIBILITY_BOTH,
        string $description = 'A product description',
        ?string $url = null,
        int $productId = 1,
        bool $salable = true,
        array $attributes = []
    ): Product {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->getMock();
        $product->method('getPrice')->willReturn($price);
        $product->method('getFinalPrice')->willReturn($finalPrice);
        $product->method('getName')->willReturn('Test Product');
        $product->method('getSku')->willReturn('TEST-SKU');
        $product->method('isSalable')->willReturn($salable);
        $product->method('getData')->with('description')->willReturn($description);
        $product->method('getAttributes')->willReturn($attributes);
        $product->method('getVisibility')->willReturn($visibility);
        $product->method('getId')->willReturn($productId);
        $product->method('getProductUrl')->willReturn($url ?? 'https://example.test/default.html');
        return $product;
    }

    private function makeAttribute(string $label, string $value, bool $isVisible): Attribute
    {
        $frontend = $this->createMock(AbstractFrontend::class);
        $frontend->method('getValue')->willReturn($value);

        $attribute = $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()->getMock();
        $attribute->method('getIsVisibleOnFront')->willReturn($isVisible);
        $attribute->method('getFrontend')->willReturn($frontend);
        $attribute->method('getStoreLabel')->willReturn($label);
        return $attribute;
    }
}
