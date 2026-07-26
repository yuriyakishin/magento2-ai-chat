<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Config\Backend;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Config\Backend\HexColor;

class HexColorTest extends TestCase
{
    /**
     * @var HexColor
     */
    private $model;

    protected function setUp(): void
    {
        $contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $eventManagerMock = $this->getMockBuilder(ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $contextMock->method('getEventDispatcher')->willReturn($eventManagerMock);

        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->model = $objectManagerHelper->getObject(HexColor::class, ['context' => $contextMock]);
    }

    /**
     * @dataProvider validValueDataProvider
     */
    public function testBeforeSaveAcceptsValidValues(string $value, string $expected): void
    {
        $this->model->setValue($value);

        $this->model->beforeSave();

        $this->assertSame($expected, $this->model->getValue());
    }

    public function validValueDataProvider(): array
    {
        return [
            'empty string is allowed' => ['', ''],
            '3-digit hex' => ['#fff', '#fff'],
            '6-digit hex' => ['#a1b2c3', '#a1b2c3'],
            'trimmed before validation' => ['  #ABCDEF  ', '#ABCDEF'],
        ];
    }

    /**
     * @dataProvider invalidValueDataProvider
     */
    public function testBeforeSaveRejectsInvalidValues(string $value): void
    {
        $this->model->setValue($value);

        $this->expectException(LocalizedException::class);

        $this->model->beforeSave();
    }

    public function invalidValueDataProvider(): array
    {
        return [
            'missing hash' => ['abc123'],
            'wrong length' => ['#ab'],
            'non-hex characters' => ['#gggggg'],
            'css color name' => ['red'],
        ];
    }
}
