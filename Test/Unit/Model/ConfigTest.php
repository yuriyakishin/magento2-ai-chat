<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Config;

class ConfigTest extends TestCase
{
    public function testIsEnabledReadsFlagAtStoreScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->isEnabled());
    }

    /**
     * @dataProvider flagDataProvider
     */
    public function testFlagGettersReadTheirOwnPath(string $method, string $path): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            [$path, ScopeInterface::SCOPE_STORE, null, true],
        ]);

        $config = new Config($scopeConfig);

        $this->assertTrue($config->$method());
    }

    public function flagDataProvider(): array
    {
        return [
            'classification enabled' => ['isClassificationEnabled', Config::XML_PATH_CLASSIFICATION_ENABLED],
            'insights enabled' => ['isInsightsEnabled', Config::XML_PATH_INSIGHTS_ENABLED],
            'like fallback enabled' => ['isLikeFallbackEnabled', Config::XML_PATH_LIKE_FALLBACK_ENABLED],
        ];
    }

    /**
     * @dataProvider stringValueDataProvider
     */
    public function testStringGettersReadTheirOwnPath(string $method, string $path): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [$path, ScopeInterface::SCOPE_STORE, null, 'configured-value'],
        ]);

        $config = new Config($scopeConfig);

        $this->assertSame('configured-value', $config->$method());
    }

    public function stringValueDataProvider(): array
    {
        return [
            'position' => ['getPosition', Config::XML_PATH_POSITION],
            'theme' => ['getTheme', Config::XML_PATH_THEME],
            'custom primary color' => ['getCustomPrimaryColor', Config::XML_PATH_CUSTOM_PRIMARY],
            'custom background color' => ['getCustomBackgroundColor', Config::XML_PATH_CUSTOM_BACKGROUND],
            'custom assistant color' => ['getCustomAssistantColor', Config::XML_PATH_CUSTOM_ASSISTANT],
            'custom text color' => ['getCustomTextColor', Config::XML_PATH_CUSTOM_TEXT],
            'title' => ['getTitle', Config::XML_PATH_TITLE],
            'welcome message' => ['getWelcomeMessage', Config::XML_PATH_WELCOME_MESSAGE],
            'system prompt' => ['getSystemPrompt', Config::XML_PATH_SYSTEM_PROMPT],
            'store facts' => ['getStoreFacts', Config::XML_PATH_STORE_FACTS],
        ];
    }

    public function testGetRetentionDaysCastsToInt(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_RETENTION_DAYS, ScopeInterface::SCOPE_STORE)
            ->willReturn('90');

        $config = new Config($scopeConfig);

        $this->assertSame(90, $config->getRetentionDays());
    }

    public function testGetTemperatureReturnsNullWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_TEMPERATURE, ScopeInterface::SCOPE_STORE)
            ->willReturn('');

        $config = new Config($scopeConfig);

        $this->assertNull($config->getTemperature());
    }

    public function testGetTemperatureReturnsNullWhenNotNumeric(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_TEMPERATURE, ScopeInterface::SCOPE_STORE)
            ->willReturn('not-a-number');

        $config = new Config($scopeConfig);

        $this->assertNull($config->getTemperature());
    }

    public function testGetTemperatureReturnsFloatWhenSet(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_TEMPERATURE, ScopeInterface::SCOPE_STORE)
            ->willReturn('0.9');

        $config = new Config($scopeConfig);

        $this->assertSame(0.9, $config->getTemperature());
    }

    public function testGetSuggestedQuestionsTrimsAndDropsEmptyLines(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_SUGGESTED_QUESTIONS, ScopeInterface::SCOPE_STORE)
            ->willReturn("  Where is my order?  \n\nDo you ship internationally?\n   \nWhat is your return policy?");

        $config = new Config($scopeConfig);

        $this->assertSame(
            ['Where is my order?', 'Do you ship internationally?', 'What is your return policy?'],
            $config->getSuggestedQuestions()
        );
    }

    public function testGetSuggestedQuestionsReturnsEmptyArrayWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');

        $config = new Config($scopeConfig);

        $this->assertSame([], $config->getSuggestedQuestions());
    }
}
