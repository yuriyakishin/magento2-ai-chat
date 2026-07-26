<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

/**
 * Resolves the widget color theme into CSS custom property values.
 *
 * Themes are supplied through di.xml so that any module can register its
 * own: add an item to the "themes" argument of this type with a "label"
 * and a "colors" array (see Yu_AiChat's etc/di.xml for the format and
 * docs/themes.md for a walkthrough).
 */
class ThemePalette
{
    public const THEME_CUSTOM = 'custom';
    public const DEFAULT_THEME = 'luma-blue';

    private const HEX_PATTERN = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * CSS variable names (without the --yu-aichat- prefix) a theme may set.
     * Anything else is ignored so third-party themes cannot inject CSS.
     */
    private const ALLOWED_KEYS = [
        'primary',
        'primary-text',
        'bg',
        'text',
        'user-bg',
        'user-text',
        'assistant-bg',
        'border',
        'muted',
        'hover',
    ];

    /**
     * @param array<string, array{label: string, colors: array<string, string>}> $themes
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $themes = []
    ) {
    }

    /**
     * @return array<string, string> theme code => admin label
     */
    public function getThemeLabels(): array
    {
        $labels = [];
        foreach ($this->themes as $code => $theme) {
            $labels[$code] = (string)($theme['label'] ?? $code);
        }
        return $labels;
    }

    /**
     * @return array<string, string> CSS variable name (without prefix) => value
     */
    public function getVariables(): array
    {
        $code = $this->config->getTheme();
        if ($code === self::THEME_CUSTOM) {
            return $this->customVariables();
        }
        $colors = $this->themes[$code]['colors']
            ?? $this->themes[self::DEFAULT_THEME]['colors']
            ?? [];
        $vars = [];
        foreach ($colors as $key => $value) {
            if (in_array($key, self::ALLOWED_KEYS, true) && preg_match(self::HEX_PATTERN, (string)$value)) {
                $vars[$key] = strtolower((string)$value);
            }
        }
        return $vars;
    }

    /**
     * @return array<string, string>
     */
    private function customVariables(): array
    {
        $vars = [];
        $custom = [
            'primary' => $this->config->getCustomPrimaryColor(),
            'bg' => $this->config->getCustomBackgroundColor(),
            'assistant-bg' => $this->config->getCustomAssistantColor(),
            'text' => $this->config->getCustomTextColor(),
        ];
        foreach ($custom as $key => $value) {
            if (preg_match(self::HEX_PATTERN, $value)) {
                $vars[$key] = strtolower($value);
            }
        }
        // Translucent grays read correctly on both light and dark custom
        // backgrounds, so these three are not exposed as admin settings.
        $vars['border'] = 'rgba(128, 128, 128, 0.35)';
        $vars['muted'] = 'rgba(128, 128, 128, 0.75)';
        $vars['hover'] = 'rgba(128, 128, 128, 0.12)';
        return $vars;
    }
}
