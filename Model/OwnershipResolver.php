<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Math\Random;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Api\Data\ConversationOwnerInterfaceFactory;

/**
 * Single place that decides who owns conversations in the web channel.
 * The raw PHP session ID must never be persisted; guests are identified
 * by a random token stored server-side in the session.
 */
class OwnershipResolver
{
    private const SESSION_KEY = 'yu_aichat_guest_token';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Random $random,
        private readonly ConversationOwnerInterfaceFactory $conversationOwnerFactory
    ) {
    }

    /**
     * @return ConversationOwnerInterface|null
     */
    public function resolve(): ?ConversationOwnerInterface
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->conversationOwnerFactory->create(['customerId' => (int)$this->customerSession->getCustomerId(), 'guestToken' => null]);
        }
        $token = $this->customerSession->getData(self::SESSION_KEY);
        return $token ? $this->conversationOwnerFactory->create(['customerId' => null, 'guestToken' => (string)$token]) : null;
    }

    /**
     * @return ConversationOwnerInterface
     */
    public function resolveOrCreate(): ConversationOwnerInterface
    {
        $owner = $this->resolve();
        if ($owner !== null) {
            return $owner;
        }
        $token = $this->random->getRandomString(32);
        $this->customerSession->setData(self::SESSION_KEY, $token);
        return $this->conversationOwnerFactory->create(['customerId' => null, 'guestToken' => $token]);
    }
}
