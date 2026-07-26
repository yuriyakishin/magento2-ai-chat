<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Yu\AiChat\Api\ChatServiceInterface;
use Yu\AiChat\Api\Data\ChatResultInterface;
use Yu\AiChat\Api\Data\ChatResultInterfaceFactory;
use Yu\AiChat\Api\Data\ConversationOwnerInterface;
use Yu\AiChat\Api\Data\PageContextInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;
use Yu\AiChat\Api\Data\ToolContextInterfaceFactory;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\Data\LlmResponseInterface;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ToolCall;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\Conversation;
use Yu\AiChat\Model\ConversationManager;
use Yu\AiChat\Model\MessageRepository;
use Yu\AiLlm\Model\ProviderConfig;
use Yu\AiChat\Model\Tools\ToolRegistry;

class ChatService implements ChatServiceInterface
{
    private const HISTORY_LIMIT = 20;
    private const MAX_TOOL_ROUNDS = 3;
    private const ERROR_REPLY = "I'm sorry — the assistant is temporarily unavailable. Please try again in a few minutes.";
    private const TOOLS_PROMPT = "\n\nYou have access to tools. Use search_products whenever the customer asks about "
    . "products, prices, availability or recommendations. Use get_product for details of a specific product. "
    . "Use compare_products (not repeated get_product calls) when the customer wants two or more named "
    . "products compared. Use get_related_products for \"what goes well with this\" / \"recommend something "
    . "similar\" questions. Use browse_category for catalog navigation by category rather than keywords. "
    . "Use check_promotions for questions about sales or discounts - never invent a coupon code, and never "
    . "state one even if a tool result seems to imply it exists. Use get_store_info for shipping, payment, "
    . "returns and policy questions. Take product names, prices, availability and URLs ONLY from tool "
    . "results — never invent them. When a product result includes a url, make the product name itself a "
    . "markdown link to it, e.g. [Karmen Yoga Pant](https://example.com/karmen.html); when a result has no "
    . "url field, just say the product name in plain text — do not guess or construct a link. Never print a raw URL as "
    . "text. If a search returns no products, say so honestly.";

    public function __construct(
        private readonly ConversationManager $conversationManager,
        private readonly MessageRepository $messageRepository,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Config $config,
        private readonly ProviderConfig $providerConfig,
        private readonly ProviderChain $providerChain,
        private readonly CostCalculator $costCalculator,
        private readonly ToolRegistry $toolRegistry,
        private readonly LoggerInterface $logger,
        private readonly LlmRequestInterfaceFactory $llmRequestFactory,
        private readonly ToolContextInterfaceFactory $toolContextFactory,
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

        // Free the session lock before the multi-second LLM calls so the
        // visitor can keep browsing while the reply is generated.
        $this->customerSession->writeClose();

        $history = $this->buildHistory((int)$conversation->getId());
        $toolContext = $this->buildToolContext($context);
        $systemPrompt = $this->buildSystemPrompt($toolContext);

        try {
            [$response, $code, $promptTokens, $completionTokens] = $this->providerChain->complete(
                function (LlmProviderInterface $provider, string $code) use (
                    $conversation,
                    $systemPrompt,
                    $history,
                    $context,
                    $toolContext
                ): array {
                    $midLoop = false;
                    try {
                        [$response, $promptTokens, $completionTokens] = $this->runToolLoop(
                            $provider,
                            $code,
                            $conversation,
                            $systemPrompt,
                            $history,
                            $context,
                            $toolContext,
                            $midLoop
                        );
                    } catch (LlmProviderException $e) {
                        $this->logger->warning(sprintf('[%s] %s', $code, $e->getMessage()));
                        // A failure after partial, already-persisted tool-call
                        // progress must not fail over to another provider.
                        throw $midLoop ? new LlmProviderException($e->getMessage(), false, $e) : $e;
                    }
                    return [$response, $code, $promptTokens, $completionTokens];
                }
            );
        } catch (LlmProviderException) {
            $this->conversationManager->addAssistantMessage($conversation, self::ERROR_REPLY, [
                'status' => 'error',
                'latency_ms' => (int)round((microtime(true) - $start) * 1000),
            ]);
            $this->conversationManager->touch($conversation);
            return $this->chatResultFactory->create([
                'conversationUuid' => $conversation->getUuid(),
                'reply' => self::ERROR_REPLY,
            ]);
        }

        $this->conversationManager->addAssistantMessage($conversation, $response->getText(), [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost' => $this->costCalculator->calculate($code, $promptTokens, $completionTokens),
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
            'provider' => $code,
            'model' => $response->getModel(),
        ]);
        $this->conversationManager->touch($conversation, $code, $response->getModel());
        return $this->chatResultFactory->create([
            'conversationUuid' => $conversation->getUuid(),
            'reply' => $response->getText(),
        ]);
    }

    /**
     * Runs the tool-calling loop with one provider. $midLoop turns true after
     * the first successful round: a later failure must not fail over to
     * another provider with a half-done tool conversation.
     *
     * @param LlmProviderInterface $provider
     * @param string $code
     * @param Conversation $conversation
     * @param string $systemPrompt
     * @param array<int, array<string, mixed>> $history
     * @param PageContextInterface $context
     * @param ToolContextInterface $toolContext
     * @param bool $midLoop
     * @return array{0: LlmResponseInterface, 1: int, 2: int}
     */
    private function runToolLoop(
        LlmProviderInterface $provider,
        string $code,
        Conversation $conversation,
        string $systemPrompt,
        array $history,
        PageContextInterface $context,
        ToolContextInterface $toolContext,
        bool &$midLoop
    ): array {
        $tools = $this->toolRegistry->getDefinitions();
        $messages = $history;
        $promptTokens = 0;
        $completionTokens = 0;

        for ($round = 0; ; $round++) {
            // On the last round tools are withheld, forcing a final answer.
            $includeTools = $tools !== [] && $round < self::MAX_TOOL_ROUNDS;
            $request = $this->llmRequestFactory->create([
                'systemPrompt' => $systemPrompt,
                'messages' => $messages,
                'model' => $this->providerConfig->getModel($code),
                'temperature' => $this->config->getTemperature(),
                'maxTokens' => $this->providerConfig->getMaxTokens(),
                'tools' => $includeTools ? $tools : [],
            ]);
            $response = $provider->send($request);
            $midLoop = true;
            $promptTokens += $response->getPromptTokens();
            $completionTokens += $response->getCompletionTokens();

            if (!$response->hasToolCalls()) {
                return [$response, $promptTokens, $completionTokens];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->getText(),
                'tool_calls' => array_map(
                    static fn(ToolCall $call): array => [
                        'id' => $call->getId(),
                        'name' => $call->getName(),
                        'arguments' => $call->getArguments(),
                    ],
                    $response->getToolCalls()
                ),
            ];
            foreach ($response->getToolCalls() as $call) {
                $result = $this->toolRegistry->execute($call->getName(), $call->getArguments(), $toolContext);
                $this->conversationManager->addToolMessage(
                    $conversation,
                    $call->getName(),
                    $call->getArguments(),
                    $result,
                    $context
                );
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->getId(),
                    'name' => $call->getName(),
                    'content' => (string)json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }
    }

    /**
     * @param int $conversationId
     * @return array<int, array{role: string, content: string}>
     */
    private function buildHistory(int $conversationId): array
    {
        $history = [];
        foreach ($this->messageRepository->getListForConversation($conversationId) as $message) {
            if ($message->getData('status') !== 'complete'
                || !in_array($message->getData('role'), ['user', 'assistant'], true)
            ) {
                continue;
            }
            $history[] = [
                'role' => (string)$message->getData('role'),
                'content' => (string)$message->getData('content'),
            ];
        }
        return array_slice($history, -self::HISTORY_LIMIT);
    }

    /**
     * @param PageContextInterface $context
     * @return ToolContextInterface
     */
    private function buildToolContext(PageContextInterface $context): ToolContextInterface
    {
        $store = $this->storeManager->getStore();
        return $this->toolContextFactory->create([
            'storeId' => (int)$store->getId(),
            'locale' => (string)$this->localeResolver->getLocale(),
            'currencyCode' => (string)$store->getCurrentCurrency()->getCode(),
            'productId' => $context->getProductId(),
            'categoryId' => $context->getCategoryId(),
            'customerGroupId' => (int)$this->customerSession->getCustomerGroupId(),
            'websiteId' => (int)$store->getWebsiteId(),
        ]);
    }

    /**
     * @param ToolContextInterface $toolContext
     * @return string
     */
    private function buildSystemPrompt(ToolContextInterface $toolContext): string
    {
        $prompt = str_replace(
            '{{store_name}}',
            (string)$this->storeManager->getStore()->getName(),
            $this->config->getSystemPrompt()
        );
        $prompt .= self::TOOLS_PROMPT;
        $prompt .= $this->pageContextLine($toolContext);
        return $prompt;
    }

    /**
     * URL-derived page context: tells the model what the visitor is looking
     * at so "is this waterproof?" needs no clarification.
     *
     * @param ToolContextInterface $toolContext
     * @return string
     */
    private function pageContextLine(ToolContextInterface $toolContext): string
    {
        try {
            if ($toolContext->getProductId() !== null) {
                $product = $this->productRepository->getById(
                    $toolContext->getProductId(),
                    false,
                    $toolContext->getStoreId()
                );
                return sprintf(
                    "\n\nThe customer is currently viewing this product page: %s (SKU %s). "
                    . 'Questions like "this", "it", "this one" refer to it — use get_product with this SKU.',
                    $product->getName(),
                    $product->getSku()
                );
            }
            if ($toolContext->getCategoryId() !== null) {
                $category = $this->categoryRepository->get($toolContext->getCategoryId(), $toolContext->getStoreId());
                return sprintf("\n\nThe customer is currently browsing the \"%s\" category.", $category->getName());
            }
        } catch (\Throwable $e) {
            // Context is best-effort; a missing entity must not break the chat.
        }
        return '';
    }
}
