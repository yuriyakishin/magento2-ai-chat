<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\ThemePalette;

class ThemePaletteTest extends TestCase
{
    private const THEMES = [
        'luma-blue' => [
            'label' => 'Luma Blue',
            'colors' => [
                'primary' => '#1979c3',
                'bg' => '#ffffff',
                'not-allowed' => '#ff0000',
                'primary-text' => 'not-a-hex-color',
            ],
        ],
        'midnight' => [
            'label' => 'Midnight',
            'colors' => ['primary' => '#000000'],
        ],
    ];

    public function testGetThemeLabelsMapsCodeToLabel(): void
    {
        $palette = new ThemePalette($this->createMock(Config::class), self::THEMES);

        $this->assertSame(
            ['luma-blue' => 'Luma Blue', 'midnight' => 'Midnight'],
            $palette->getThemeLabels()
        );
    }

    public function testGetThemeLabelsFallsBackToCodeWhenLabelMissing(): void
    {
        $palette = new ThemePalette($this->createMock(Config::class), ['custom-theme' => ['colors' => []]]);

        $this->assertSame(['custom-theme' => 'custom-theme'], $palette->getThemeLabels());
    }

    public function testGetVariablesReturnsAllowedHexColorsOnlyLowercased(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getTheme')->willReturn('luma-blue');
        $palette = new ThemePalette($config, self::THEMES);

        $vars = $palette->getVariables();

        $this->assertSame(['primary' => '#1979c3', 'bg' => '#ffffff'], $vars);
    }

    public function testGetVariablesFallsBackToDefaultThemeWhenCodeUnknown(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getTheme')->willReturn('nonexistent-theme');
        $palette = new ThemePalette($config, self::THEMES);

        $this->assertSame(['primary' => '#1979c3', 'bg' => '#ffffff'], $palette->getVariables());
    }

    public function testGetVariablesReturnsEmptyArrayWhenNoThemesRegisteredAtAll(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getTheme')->willReturn('anything');
        $palette = new ThemePalette($config, []);

        $this->assertSame([], $palette->getVariables());
    }

    public function testCustomThemeUsesConfiguredColorsAndFixedTranslucentGrays(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getTheme')->willReturn(ThemePalette::THEME_CUSTOM);
        $config->method('getCustomPrimaryColor')->willReturn('#AABBCC');
        $config->method('getCustomBackgroundColor')->willReturn('#111111');
        $config->method('getCustomAssistantColor')->willReturn('');
        $config->method('getCustomTextColor')->willReturn('not-a-hex-color');
        $palette = new ThemePalette($config, self::THEMES);

        $vars = $palette->getVariables();

        $this->assertSame('#aabbcc', $vars['primary']);
        $this->assertSame('#111111', $vars['bg']);
        $this->assertArrayNotHasKey('assistant-bg', $vars);
        $this->assertArrayNotHasKey('text', $vars);
        $this->assertSame('rgba(128, 128, 128, 0.35)', $vars['border']);
        $this->assertSame('rgba(128, 128, 128, 0.75)', $vars['muted']);
        $this->assertSame('rgba(128, 128, 128, 0.12)', $vars['hover']);
    }
}
