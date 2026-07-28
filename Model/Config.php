<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'yu_aichat/general/enabled';
    public const XML_PATH_POSITION = 'yu_aichat/chat/position';
    public const XML_PATH_THEME = 'yu_aichat/chat/theme';
    public const XML_PATH_CUSTOM_PRIMARY = 'yu_aichat/chat/custom_primary_color';
    public const XML_PATH_CUSTOM_BACKGROUND = 'yu_aichat/chat/custom_background_color';
    public const XML_PATH_CUSTOM_ASSISTANT = 'yu_aichat/chat/custom_assistant_color';
    public const XML_PATH_CUSTOM_TEXT = 'yu_aichat/chat/custom_text_color';
    public const XML_PATH_TITLE = 'yu_aichat/chat/title';
    public const XML_PATH_WELCOME_MESSAGE = 'yu_aichat/chat/welcome_message';
    public const XML_PATH_SUGGESTED_QUESTIONS = 'yu_aichat/chat/suggested_questions';
    public const XML_PATH_SYSTEM_PROMPT = 'yu_aichat/chat/system_prompt';
    public const XML_PATH_STORE_FACTS = 'yu_aichat/chat/store_facts';
    public const XML_PATH_TEMPERATURE = 'yu_aichat/chat/temperature';
    public const XML_PATH_CLASSIFICATION_ENABLED = 'yu_aichat/analytics/classification_enabled';
    public const XML_PATH_INSIGHTS_ENABLED = 'yu_aichat/analytics/insights_enabled';
    public const XML_PATH_RETENTION_DAYS = 'yu_aichat/analytics/retention_days';
    public const XML_PATH_LIKE_FALLBACK_ENABLED = 'yu_aichat/search/like_fallback_enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getPosition(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_POSITION, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getTheme(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_THEME, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getCustomPrimaryColor(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_CUSTOM_PRIMARY, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getCustomBackgroundColor(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_CUSTOM_BACKGROUND, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getCustomAssistantColor(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_CUSTOM_ASSISTANT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getCustomTextColor(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_CUSTOM_TEXT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_TITLE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getWelcomeMessage(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_WELCOME_MESSAGE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getSystemPrompt(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SYSTEM_PROMPT, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getStoreFacts(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_STORE_FACTS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Null when unset - LlmRequest treats null as "use the provider's own
     * configured default temperature" (Yu_AiLlm's shared setting).
     *
     * @return float|null
     */
    public function getTemperature(): ?float
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_TEMPERATURE, ScopeInterface::SCOPE_STORE);
        return $value !== '' && is_numeric($value) ? (float)$value : null;
    }

    /**
     * @return bool
     */
    public function isClassificationEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CLASSIFICATION_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function isInsightsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_INSIGHTS_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getRetentionDays(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_RETENTION_DAYS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function isLikeFallbackEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LIKE_FALLBACK_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string[]
     */
    public function getSuggestedQuestions(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_PATH_SUGGESTED_QUESTIONS, ScopeInterface::SCOPE_STORE);
        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }
}
