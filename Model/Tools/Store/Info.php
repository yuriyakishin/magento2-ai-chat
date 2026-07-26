<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Store;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Api\Data\ToolContextInterface;

class Info implements ToolInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'get_store_info';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Get factual store information: currently available shipping methods, accepted '
            . 'payment methods, and store policies (returns, delivery times, warranty). Call it for '
            . 'any question about shipping, payment, returns or store policies.';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        return [
            'store_name' => (string)$this->storeManager->getStore($context->getStoreId())->getName(),
            'shipping_methods' => $this->activeTitles('carriers', $context->getStoreId()),
            'payment_methods' => $this->activeTitles('payment', $context->getStoreId()),
            'facts' => $this->config->getStoreFacts(),
        ];
    }

    /**
     * Reads live Magento config: whatever is enabled right now is what the
     * assistant reports — no manually maintained duplicate lists.
     *
     * @param string $section
     * @param int $storeId
     * @return string[]
     */
    private function activeTitles(string $section, int $storeId): array
    {
        $titles = [];
        $methods = (array)$this->scopeConfig->getValue($section, ScopeInterface::SCOPE_STORE, $storeId);
        foreach ($methods as $methodConfig) {
            if (!empty($methodConfig['active']) && !empty($methodConfig['title'])) {
                $titles[] = (string)$methodConfig['title'];
            }
        }
        return array_values(array_unique($titles));
    }
}
