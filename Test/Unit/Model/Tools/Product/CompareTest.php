<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Tools\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Tools\Product\Compare;
use Yu\AiChat\Model\Tools\Product\ProductFormatter;
use Yu\AiChat\Model\Tools\ToolContext;

class CompareTest extends TestCase
{
    public function testExecuteReturnsErrorWhenFewerThanTwoValidSkusProvided(): void
    {
        $compare = $this->makeTool($this->createMock(ProductRepositoryInterface::class), $this->createMock(ProductFormatter::class));

        $result = $compare->execute(['skus' => ['ONLY-ONE']], $this->createMock(ToolContext::class));

        $this->assertSame(['error' => 'Provide at least two SKUs to compare'], $result);
    }

    public function testExecuteDeduplicatesSkusBeforeCountingThem(): void
    {
        $compare = $this->makeTool($this->createMock(ProductRepositoryInterface::class), $this->createMock(ProductFormatter::class));

        // "A" appears twice; after de-dup only one distinct SKU remains,
        // which is still below the two-SKU minimum.
        $result = $compare->execute(['skus' => ['A', 'A']], $this->createMock(ToolContext::class));

        $this->assertSame(['error' => 'Provide at least two SKUs to compare'], $result);
    }

    public function testExecuteLimitsToMaxFourProductsEvenWithMoreSkusGiven(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->exactly(4))->method('get')->willReturn($this->createMock(Product::class));
        $formatter = $this->createMock(ProductFormatter::class);
        $formatter->method('format')->willReturn(['name' => 'x']);

        $compare = $this->makeTool($productRepository, $formatter);

        $compare->execute(['skus' => ['A', 'B', 'C', 'D', 'E', 'F']], $this->createMock(ToolContext::class));
    }

    public function testExecuteReturnsFormattedProductsForFoundSkus(): void
    {
        $productA = $this->createMock(Product::class);
        $productB = $this->createMock(Product::class);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturnMap([
            ['A', false, 1, false, $productA],
            ['B', false, 1, false, $productB],
        ]);
        $formatter = $this->createMock(ProductFormatter::class);
        $formatter->method('format')->willReturnCallback(
            static fn(Product $product) => $product === $productA ? ['name' => 'Product A'] : ['name' => 'Product B']
        );

        $compare = $this->makeTool($productRepository, $formatter);

        $result = $compare->execute(['skus' => ['A', 'B']], $this->makeContext());

        $this->assertSame(['products' => [['name' => 'Product A'], ['name' => 'Product B']]], $result);
    }

    public function testExecuteReportsNotFoundSkusAlongsideFoundProducts(): void
    {
        $productA = $this->createMock(Product::class);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturnCallback(
            function (string $sku) use ($productA) {
                if ($sku === 'MISSING') {
                    throw new NoSuchEntityException(__('gone'));
                }
                return $productA;
            }
        );
        $formatter = $this->createMock(ProductFormatter::class);
        $formatter->method('format')->willReturn(['name' => 'Product A']);

        $compare = $this->makeTool($productRepository, $formatter);

        $result = $compare->execute(['skus' => ['A', 'B', 'MISSING']], $this->makeContext());

        $this->assertSame(
            ['products' => [['name' => 'Product A'], ['name' => 'Product A']], 'not_found' => ['MISSING']],
            $result
        );
    }

    public function testExecuteReturnsErrorWhenFewerThanTwoProductsAreActuallyFound(): void
    {
        $productA = $this->createMock(Product::class);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('get')->willReturnCallback(
            function (string $sku) use ($productA) {
                if ($sku === 'A') {
                    return $productA;
                }
                throw new NoSuchEntityException(__('gone'));
            }
        );
        $formatter = $this->createMock(ProductFormatter::class);
        $formatter->method('format')->willReturn(['name' => 'Product A']);

        $compare = $this->makeTool($productRepository, $formatter);

        $result = $compare->execute(['skus' => ['A', 'MISSING1', 'MISSING2']], $this->makeContext());

        $this->assertSame('Could not find at least two of the given products', $result['error']);
        $this->assertSame(['MISSING1', 'MISSING2'], $result['not_found']);
    }

    public function testExecuteTrimsWhitespaceFromSkusBeforeLookup(): void
    {
        $product = $this->createMock(Product::class);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->exactly(2))
            ->method('get')
            ->with($this->logicalOr('A', 'B'), false, 1)
            ->willReturn($product);
        $formatter = $this->createMock(ProductFormatter::class);
        $formatter->method('format')->willReturn(['name' => 'x']);

        $compare = $this->makeTool($productRepository, $formatter);

        $compare->execute(['skus' => ['  A  ', ' B']], $this->makeContext());
    }

    public function testExecuteIgnoresNonArraySkusArgument(): void
    {
        $compare = $this->makeTool($this->createMock(ProductRepositoryInterface::class), $this->createMock(ProductFormatter::class));

        $result = $compare->execute(['skus' => 'not-an-array'], $this->createMock(ToolContext::class));

        $this->assertSame(['error' => 'Provide at least two SKUs to compare'], $result);
    }

    private function makeTool(ProductRepositoryInterface $productRepository, ProductFormatter $formatter): Compare
    {
        return new Compare($productRepository, $formatter);
    }

    private function makeContext(): ToolContext
    {
        return new ToolContext(1, 'en_US', 'USD', null, null);
    }
}
