<?php
declare(strict_types=1);

namespace Yu\AiChat\Block;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\Element\Template;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\ThemePalette;

class Widget extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        private readonly ThemePalette $themePalette,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Values are theme constants from di.xml or hex-validated admin input;
     * ThemePalette drops anything that does not match its color patterns.
     *
     * @return string
     */
    public function getThemeCss(): string
    {
        $css = '';
        foreach ($this->themePalette->getVariables() as $name => $value) {
            $css .= '--yu-aichat-' . $name . ':' . $value . ';';
        }
        return '#yu-aichat{' . $css . '}';
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Page context is derived from the URL only (action name + entity id),
     * never from visitor state, so the output stays full-page-cache safe.
     *
     * @return string
     */
    public function getJsonConfig(): string
    {
        $request = $this->getRequest();
        $fullAction = $request instanceof HttpRequest ? $request->getFullActionName() : 'other';
        [$pageType, $productId, $categoryId] = match ($fullAction) {
            'cms_index_index' => ['home', null, null],
            'catalog_product_view' => ['product', (int)$request->getParam('id') ?: null, null],
            'catalog_category_view' => ['category', null, (int)$request->getParam('id') ?: null],
            'cms_page_view' => ['cms', null, null],
            default => ['other', null, null],
        };
        return (string)json_encode([
            'urls' => [
                'send' => $this->getUrl('aichat/chat/send'),
                'conversations' => $this->getUrl('aichat/conversation/index'),
                'messages' => $this->getUrl('aichat/conversation/view'),
            ],
            'position' => $this->config->getPosition(),
            'title' => $this->config->getTitle(),
            'welcomeMessage' => $this->config->getWelcomeMessage(),
            'suggestedQuestions' => $this->config->getSuggestedQuestions(),
            'context' => [
                'page_type' => $pageType,
                'product_id' => $productId,
                'category_id' => $categoryId,
            ],
        ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
    }
}
