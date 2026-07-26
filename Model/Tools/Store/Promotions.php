<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools\Store;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;

/**
 * Coupon CODES are deliberately never exposed: whether a code has been
 * published is a marketing decision, not something the assistant should
 * make for the merchant. Only the fact that a coupon is required is
 * reported, and any occurrence of the code inside the rule's own
 * name/description text is redacted before it leaves the tool (merchants
 * routinely paste the code there, e.g. "use code X at checkout").
 */
class Promotions implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly CollectionFactory $ruleCollectionFactory,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'check_promotions';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Get currently active store-wide promotions: name, description, discount and validity '
            . 'dates. Call it for questions about sales, discounts or promotions. Coupon codes themselves '
            . 'are never returned - only whether a promotion requires one.';
    }

    /**
     * @return array
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max promotions to return, default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT,
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * @param array $args
     * @param ToolContextInterface $context
     * @return array
     */
    public function execute(array $args, ToolContextInterface $context): array
    {
        $limit = isset($args['limit']) && is_numeric($args['limit'])
            ? max(1, min(self::MAX_LIMIT, (int)$args['limit']))
            : self::DEFAULT_LIMIT;
        $today = $this->timezone->date()->format('Y-m-d');

        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('from_date', [['null' => true], ['lteq' => $today]]);
        $collection->addFieldToFilter('to_date', [['null' => true], ['gteq' => $today]]);
        $collection->setPageSize($limit);

        $promotions = [];
        foreach ($collection as $rule) {
            $couponRequired = (int)$rule->getData('coupon_type') !== Rule::COUPON_TYPE_NO_COUPON;
            $couponCode = $couponRequired ? $this->couponCode($rule) : null;
            $promotions[] = [
                'name' => $this->redactCouponCode((string)$rule->getData('name'), $couponCode),
                'description' => $this->redactCouponCode((string)$rule->getData('description'), $couponCode),
                'to_date' => $rule->getData('to_date'),
                'action' => $rule->getData('simple_action'),
                'discount_amount' => (float)$rule->getData('discount_amount'),
                'coupon_required' => $couponRequired,
            ];
        }

        if ($promotions === []) {
            return ['promotions' => [], 'count' => 0, 'message' => 'No active promotions right now'];
        }
        return ['promotions' => $promotions, 'count' => count($promotions)];
    }

    /**
     * The rule's primary coupon code, or null when none is set.
     *
     * @param Rule $rule
     * @return string|null
     */
    private function couponCode(Rule $rule): ?string
    {
        $code = $rule->getPrimaryCoupon() ? (string)$rule->getPrimaryCoupon()->getCode() : '';
        return $code === '' ? null : $code;
    }

    /**
     * Removes every occurrence of the rule's coupon code (case-insensitive)
     * from merchant-authored text, so the code can't leak through
     * name/description.
     *
     * @param string $text
     * @param string|null $couponCode
     * @return string
     */
    private function redactCouponCode(string $text, ?string $couponCode): string
    {
        if ($couponCode === null || $text === '') {
            return $text;
        }
        return trim(str_ireplace($couponCode, '[coupon code hidden]', $text));
    }
}
