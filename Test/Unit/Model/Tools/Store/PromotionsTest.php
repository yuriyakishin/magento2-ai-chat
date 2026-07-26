<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Tools\Store;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Tools\Store\Promotions;
use Yu\AiChat\Model\Tools\ToolContext;

class PromotionsTest extends TestCase
{
    public function testExecuteReturnsNoActivePromotionsMessageWhenCollectionIsEmpty(): void
    {
        $promotions = $this->makeTool([]);

        $result = $promotions->execute([], $this->createMock(ToolContext::class));

        $this->assertSame(['promotions' => [], 'count' => 0, 'message' => 'No active promotions right now'], $result);
    }

    public function testExecuteNeverExposesTheCouponCodeItself(): void
    {
        $rule = $this->makeRule('name', 'desc', 'SAVE20', couponType: Rule::COUPON_TYPE_SPECIFIC);
        $promotions = $this->makeTool([$rule]);

        $result = $promotions->execute([], $this->createMock(ToolContext::class));

        $encoded = (string)json_encode($result);
        $this->assertStringNotContainsString('SAVE20', $encoded);
        $this->assertTrue($result['promotions'][0]['coupon_required']);
    }

    public function testExecuteRedactsCouponCodeFromNameAndDescriptionCaseInsensitively(): void
    {
        $rule = $this->makeRule('Use code save20 today!', 'Enter save20 at checkout for 20% off', 'SAVE20', couponType: Rule::COUPON_TYPE_SPECIFIC);
        $promotions = $this->makeTool([$rule]);

        $result = $promotions->execute([], $this->createMock(ToolContext::class));

        $this->assertSame('Use code [coupon code hidden] today!', $result['promotions'][0]['name']);
        $this->assertSame('Enter [coupon code hidden] at checkout for 20% off', $result['promotions'][0]['description']);
    }

    public function testExecuteDoesNotRedactAnythingWhenPromotionHasNoCoupon(): void
    {
        $rule = $this->makeRule('Site-wide sale', 'Everything 10% off', null, couponType: Rule::COUPON_TYPE_NO_COUPON);
        $promotions = $this->makeTool([$rule]);

        $result = $promotions->execute([], $this->createMock(ToolContext::class));

        $this->assertSame('Site-wide sale', $result['promotions'][0]['name']);
        $this->assertSame('Everything 10% off', $result['promotions'][0]['description']);
        $this->assertFalse($result['promotions'][0]['coupon_required']);
    }

    public function testExecuteClampsLimitToConfiguredMaximum(): void
    {
        $collection = $this->makeCollectionMock([]);
        $collection->expects($this->once())->method('setPageSize')->with(20);
        $promotions = $this->makeToolWithCollection($collection);

        $promotions->execute(['limit' => 500], $this->createMock(ToolContext::class));
    }

    public function testExecuteFallsBackToDefaultLimitWhenArgumentIsNotNumeric(): void
    {
        $collection = $this->makeCollectionMock([]);
        $collection->expects($this->once())->method('setPageSize')->with(10);
        $promotions = $this->makeToolWithCollection($collection);

        $promotions->execute(['limit' => 'lots'], $this->createMock(ToolContext::class));
    }

    /**
     * @param Rule[] $rules
     */
    private function makeTool(array $rules): Promotions
    {
        return $this->makeToolWithCollection($this->makeCollectionMock($rules));
    }

    /**
     * @param Collection&\PHPUnit\Framework\MockObject\MockObject $collection
     */
    private function makeToolWithCollection($collection): Promotions
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-06-01'));

        return new Promotions($factory, $timezone);
    }

    /**
     * @param Rule[] $rules
     * @return Collection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeCollectionMock(array $rules)
    {
        $collection = $this->getMockBuilder(Collection::class)->disableOriginalConstructor()->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));
        return $collection;
    }

    private function makeRule(string $name, string $description, ?string $couponCode, int $couponType): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->getMock();
        $rule->method('getData')->willReturnMap([
            ['name', null, $name],
            ['description', null, $description],
            ['to_date', null, '2026-12-31'],
            ['simple_action', null, 'by_percent'],
            ['discount_amount', null, '20'],
            ['coupon_type', null, (string)$couponType],
        ]);
        if ($couponCode !== null) {
            $coupon = $this->getMockBuilder(Coupon::class)->disableOriginalConstructor()->getMock();
            $coupon->method('getCode')->willReturn($couponCode);
            $rule->method('getPrimaryCoupon')->willReturn($coupon);
        } else {
            $rule->method('getPrimaryCoupon')->willReturn(null);
        }
        return $rule;
    }
}
