<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Yu\AiChat\Api\ChatServiceInterface;
use Yu\AiChat\Api\Data\ChatResultInterface;
use Yu\AiChat\Api\Data\ChatResultInterfaceFactory;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Api\Data\PageContextInterface;

/**
 * Development stand-in kept after Phase 2: switch the preference in di.xml
 * back to this class to demo the widget without any API keys.
 */
class StubChatService implements ChatServiceInterface
{
    private const STUB_REPLY = "**Test reply** from the stub AI service — real AI providers arrive in Phase 2.\n\n"
    . "Things I can already render:\n\n"
    . "- Lists like this one\n"
    . "- **Bold** and *italic* text\n"
    . "- [A link to the homepage](/)\n"
    . "- `inline code`\n\n"
    . "Keep chatting to test conversations, history and the typing indicator!";

    public function __construct(
        private readonly ConversationManager $conversationManager,
        private readonly CustomerSession $customerSession,
        private readonly ChatResultInterfaceFactory $chatResultFactory
    ) {
    }

    /**
     * @param ConversationOwnerInterface $owner
     * @param string|null $conversationUuid
     * @param string $message
     * @param PageContextInterface $context
     * @return ChatResultInterface
     */
    public function send(
        ConversationOwnerInterface $owner,
        ?string $conversationUuid,
        string $message,
        PageContextInterface $context
    ): ChatResultInterface {
        $start = microtime(true);
        $conversation = $this->conversationManager->resolveConversation($owner, $conversationUuid, $message);
        $this->conversationManager->addUserMessage($conversation, $message, $context);

        // Release the session lock before the simulated slow call, exactly
        // like the real LLM implementation does.
        $this->customerSession->writeClose();
        usleep(800000);

        $this->conversationManager->addAssistantMessage($conversation, self::STUB_REPLY, [
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
        ]);
        $this->conversationManager->touch($conversation);
        return $this->chatResultFactory->create([
            'conversationUuid' => $conversation->getUuid(),
            'reply' => self::STUB_REPLY,
        ]);
    }
}
