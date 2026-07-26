<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Api\Data\ChatResultInterfaceFactory;
use Yu\AiChat\Api\Data\ToolContextInterfaceFactory;
use Yu\AiChat\Model\ChatResult;
use Yu\AiChat\Model\ChatService;
use Yu\AiChat\Model\Config;
use Yu\AiChat\Model\Conversation;
use Yu\AiChat\Model\ConversationManager;
use Yu\AiChat\Model\ConversationOwner;
use Yu\AiChat\Model\Message;
use Yu\AiChat\Model\MessageRepository;
use Yu\AiChat\Model\PageContext;
use Yu\AiChat\Model\Tools\ToolContext;
use Yu\AiChat\Model\Tools\ToolRegistry;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\LlmRequest;
use Yu\AiLlm\Model\LlmResponse;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;
use Yu\AiLlm\Model\ToolCall;

class ChatServiceTest extends TestCase
{
    public function testSendHappyPathPersistsAssistantMessageWithUsageAndReturnsReply(): void
    {
        $conversation = $this->makeConversation();
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($conversation);
        $conversationManager->expects($this->once())->method('addUserMessage');
        $capturedMeta = null;
        $conversationManager->expects($this->once())->method('addAssistantMessage')->with(
            $conversation,
            'Hello there!',
            $this->callback(function (array $meta) use (&$capturedMeta): bool {
                $capturedMeta = $meta;
                return true;
            })
        );
        $conversationManager->expects($this->once())->method('touch')->with($conversation, 'openai', 'gpt-4o');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('Hello there!', 10, 5, 'gpt-4o'));
        $providerChain = $this->makeProviderChain($provider, 'openai');
        $costCalculator = $this->createMock(CostCalculator::class);
        $costCalculator->method('calculate')->with('openai', 10, 5)->willReturn(0.001);

        $service = $this->makeService(
            conversationManager: $conversationManager,
            providerChain: $providerChain,
            costCalculator: $costCalculator
        );

        $result = $service->send(new ConversationOwner(1, null), null, 'hi', $this->makeContext());

        $this->assertSame('Hello there!', $result->getReply());
        $this->assertSame(10, $capturedMeta['prompt_tokens']);
        $this->assertSame(5, $capturedMeta['completion_tokens']);
        $this->assertSame(15, $capturedMeta['total_tokens']);
        $this->assertSame(0.001, $capturedMeta['cost']);
        $this->assertSame('openai', $capturedMeta['provider']);
        $this->assertSame('gpt-4o', $capturedMeta['model']);
    }

    public function testSendPassesYuAiChatsOwnConfiguredTemperatureToLlmRequest(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $config = $this->createMock(Config::class);
        $config->method('getSystemPrompt')->willReturn('You are a helpful assistant.');
        $config->method('getTemperature')->willReturn(0.9);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('ok', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(conversationManager: $conversationManager, config: $config, providerChain: $providerChain);
        $service->send(new ConversationOwner(1, null), null, 'hi', $this->makeContext());

        $this->assertSame(0.9, $capturedRequest->getTemperature());
    }

    public function testSendPassesNullTemperatureWhenYuAiChatHasNoOverrideConfigured(): void
    {
        // null lets each Yu_AiLlm provider fall back to its own configured
        // default temperature - ChatService must not resolve this itself.
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $config = $this->createMock(Config::class);
        $config->method('getSystemPrompt')->willReturn('You are a helpful assistant.');
        $config->method('getTemperature')->willReturn(null);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('ok', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(conversationManager: $conversationManager, config: $config, providerChain: $providerChain);
        $service->send(new ConversationOwner(1, null), null, 'hi', $this->makeContext());

        $this->assertNull($capturedRequest->getTemperature());
    }

    public function testSendFreesTheSessionLockBeforeCallingTheProvider(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())->method('writeClose');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('ok', 1, 1, 'gpt-4o'));
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            providerChain: $providerChain,
            customerSession: $customerSession
        );

        $service->send(new ConversationOwner(1, null), null, 'hi', $this->makeContext());
    }

    public function testToolLoopExecutesToolsAndPersistsToolMessagesBeforeFinalReply(): void
    {
        $conversation = $this->makeConversation();
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($conversation);
        $conversationManager->expects($this->once())->method('addToolMessage')->with(
            $conversation,
            'search_products',
            ['query' => 'shoes'],
            ['count' => 2],
            $this->isInstanceOf(PageContext::class)
        );

        $toolCall = new ToolCall('call_1', 'search_products', ['query' => 'shoes']);
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnOnConsecutiveCalls(
            new LlmResponse('', 5, 5, 'gpt-4o', [$toolCall]),
            new LlmResponse('Found some shoes!', 3, 4, 'gpt-4o')
        );
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->method('getDefinitions')->willReturn([
            ['name' => 'search_products', 'description' => 'x', 'parameters' => []],
        ]);
        $toolRegistry->expects($this->once())
            ->method('execute')
            ->with('search_products', ['query' => 'shoes'], $this->anything())
            ->willReturn(['count' => 2]);

        $service = $this->makeService(
            conversationManager: $conversationManager,
            providerChain: $providerChain,
            toolRegistry: $toolRegistry
        );

        $result = $service->send(new ConversationOwner(1, null), null, 'find shoes', $this->makeContext());

        $this->assertSame('Found some shoes!', $result->getReply());
    }

    public function testToolLoopSumsTokensAcrossRounds(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $capturedMeta = null;
        $conversationManager->method('addAssistantMessage')->willReturnCallback(
            function ($conv, $text, $meta) use (&$capturedMeta) {
                $capturedMeta = $meta;
                return $this->createMock(Message::class);
            }
        );

        $toolCall = new ToolCall('call_1', 'search_products', []);
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnOnConsecutiveCalls(
            new LlmResponse('', 10, 10, 'gpt-4o', [$toolCall]),
            new LlmResponse('final', 5, 5, 'gpt-4o')
        );
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->method('getDefinitions')->willReturn([['name' => 'search_products', 'description' => 'x', 'parameters' => []]]);
        $toolRegistry->method('execute')->willReturn([]);

        $service = $this->makeService(conversationManager: $conversationManager, providerChain: $providerChain, toolRegistry: $toolRegistry);

        $service->send(new ConversationOwner(1, null), null, 'x', $this->makeContext());

        $this->assertSame(15, $capturedMeta['prompt_tokens']);
        $this->assertSame(15, $capturedMeta['completion_tokens']);
    }

    public function testToolLoopWithholdsToolsOnFinalRoundToForceAnAnswer(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $conversationManager->method('addAssistantMessage')->willReturn($this->createMock(Message::class));

        $toolCall = new ToolCall('call_1', 'search_products', []);
        $capturedRequests = [];
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(
            function (LlmRequest $request) use (&$capturedRequests, $toolCall) {
                $capturedRequests[] = $request;
                // Keep returning tool calls forever; the loop must still
                // terminate once tools are withheld on the last round.
                return count($capturedRequests) <= 3
                    ? new LlmResponse('', 1, 1, 'gpt-4o', [$toolCall])
                    : new LlmResponse('forced final answer', 1, 1, 'gpt-4o');
            }
        );
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->method('getDefinitions')->willReturn([['name' => 'search_products', 'description' => 'x', 'parameters' => []]]);
        $toolRegistry->method('execute')->willReturn([]);

        $service = $this->makeService(conversationManager: $conversationManager, providerChain: $providerChain, toolRegistry: $toolRegistry);

        $result = $service->send(new ConversationOwner(1, null), null, 'x', $this->makeContext());

        $this->assertSame('forced final answer', $result->getReply());
        // Rounds 0-2 include tools; round 3 (index 3, the 4th call) must not.
        $this->assertNotEmpty($capturedRequests[0]->getTools());
        $this->assertNotEmpty($capturedRequests[1]->getTools());
        $this->assertNotEmpty($capturedRequests[2]->getTools());
        $this->assertSame([], $capturedRequests[3]->getTools());
    }

    public function testFailureAfterSuccessfulRoundDoesNotTriggerProviderFailover(): void
    {
        // A tool-call round already ran; a later failure in the SAME
        // provider call must not fail over (would replay a half-done
        // tool-calling conversation on a different provider).
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $conversationManager->method('addAssistantMessage')->willReturn($this->createMock(Message::class));

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback((function () {
            $call = 0;
            return function () use (&$call) {
                $call++;
                if ($call === 1) {
                    return new LlmResponse('', 1, 1, 'gpt-4o', [new ToolCall('call_1', 'search_products', [])]);
                }
                throw new LlmProviderException('mid-loop network blip', true);
            };
        })());

        $toolRegistry = $this->createMock(ToolRegistry::class);
        $toolRegistry->method('getDefinitions')->willReturn([['name' => 'search_products', 'description' => 'x', 'parameters' => []]]);
        $toolRegistry->method('execute')->willReturn([]);

        // send() itself catches any LlmProviderException complete() raises
        // and turns it into a friendly ChatResult, so the exception never
        // reaches this test through send(). Capture the attempt closure
        // instead and invoke it directly to inspect what IT throws.
        $capturedAttempt = null;
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('complete')->willReturnCallback(
            function (callable $attempt) use (&$capturedAttempt) {
                $capturedAttempt = $attempt;
                return [new LlmResponse('placeholder', 1, 1, 'gpt-4o'), 'openai', 1, 1];
            }
        );

        $service = $this->makeService(conversationManager: $conversationManager, providerChain: $providerChain, toolRegistry: $toolRegistry);
        $service->send(new ConversationOwner(1, null), null, 'x', $this->makeContext());

        $this->assertNotNull($capturedAttempt);
        try {
            $capturedAttempt($provider, 'openai');
            $this->fail('Expected the attempt closure to rethrow');
        } catch (LlmProviderException $e) {
            $this->assertFalse($e->isProviderUnavailable());
            $this->assertSame('mid-loop network blip', $e->getMessage());
        }
    }

    public function testFailureBeforeAnySuccessfulRoundPreservesOriginalAvailabilityFlag(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willThrowException(new LlmProviderException('down before first reply', true));

        $capturedAttempt = null;
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('complete')->willReturnCallback(
            function (callable $attempt) use (&$capturedAttempt) {
                $capturedAttempt = $attempt;
                return [new LlmResponse('placeholder', 1, 1, 'gpt-4o'), 'openai', 1, 1];
            }
        );

        $service = $this->makeService(conversationManager: $conversationManager, providerChain: $providerChain);
        $service->send(new ConversationOwner(1, null), null, 'x', $this->makeContext());

        $this->assertNotNull($capturedAttempt);
        try {
            $capturedAttempt($provider, 'openai');
            $this->fail('Expected the attempt closure to rethrow');
        } catch (LlmProviderException $e) {
            $this->assertTrue($e->isProviderUnavailable());
            $this->assertSame('down before first reply', $e->getMessage());
        }
    }

    public function testSendReturnsErrorReplyWhenAllProvidersAreExhausted(): void
    {
        $conversation = $this->makeConversation();
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($conversation);
        $capturedMeta = null;
        $conversationManager->expects($this->once())->method('addAssistantMessage')->with(
            $conversation,
            "I'm sorry — the assistant is temporarily unavailable. Please try again in a few minutes.",
            $this->callback(function (array $meta) use (&$capturedMeta): bool {
                $capturedMeta = $meta;
                return true;
            })
        );
        $conversationManager->expects($this->once())->method('touch')->with($conversation);

        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('complete')->willThrowException(new LlmProviderException('All LLM providers are unavailable', true));

        $service = $this->makeService(conversationManager: $conversationManager, providerChain: $providerChain);

        $result = $service->send(new ConversationOwner(1, null), null, 'x', $this->makeContext());

        $this->assertStringContainsString('temporarily unavailable', $result->getReply());
        $this->assertSame('error', $capturedMeta['status']);
    }

    public function testBuildHistoryIncludesOnlyCompleteUserAndAssistantMessages(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('getListForConversation')->willReturn([
            $this->makeMessage('user', 'hello', 'complete'),
            $this->makeMessage('assistant', 'hi there', 'complete'),
            $this->makeMessage('tool', '{"tool":"x"}', 'complete'),
            $this->makeMessage('assistant', 'in progress...', 'pending'),
        ]);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('reply', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            messageRepository: $messageRepository,
            providerChain: $providerChain
        );

        $service->send(new ConversationOwner(1, null), 'existing-uuid', 'new message', $this->makeContext());

        $historyMessages = array_slice($capturedRequest->getMessages(), 0, 2);
        $this->assertSame(
            [['role' => 'user', 'content' => 'hello'], ['role' => 'assistant', 'content' => 'hi there']],
            $historyMessages
        );
    }

    public function testBuildSystemPromptSubstitutesStoreNameAndAppendsToolsPrompt(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $config = $this->createMock(Config::class);
        $config->method('getSystemPrompt')->willReturn('Welcome to {{store_name}}!');

        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()->getMock();
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getName')->willReturn('Acme Store');
        $currency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currency->method('getCode')->willReturn('USD');
        $store->method('getCurrentCurrency')->willReturn($currency);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('reply', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            config: $config,
            storeManager: $storeManager,
            providerChain: $providerChain
        );

        $service->send(new ConversationOwner(1, null), null, 'hi', $this->makeContext());

        $this->assertStringStartsWith('Welcome to Acme Store!', $capturedRequest->getSystemPrompt());
        $this->assertStringContainsString('You have access to tools.', $capturedRequest->getSystemPrompt());
    }

    public function testBuildSystemPromptAddsProductPageContextLineForCurrentProduct(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Karmen Yoga Pant');
        $product->method('getSku')->willReturn('KARMEN-1');
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->with(42, false, 1)->willReturn($product);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('reply', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            productRepository: $productRepository,
            providerChain: $providerChain
        );

        $context = new PageContext('product', 42, null, null, null, 0);
        $service->send(new ConversationOwner(1, null), null, 'is this waterproof?', $context);

        $this->assertStringContainsString('Karmen Yoga Pant', $capturedRequest->getSystemPrompt());
        $this->assertStringContainsString('KARMEN-1', $capturedRequest->getSystemPrompt());
    }

    public function testBuildSystemPromptAddsCategoryPageContextLineWhenNoProduct(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Yoga Pants');
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->with(7, 1)->willReturn($category);

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('reply', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            categoryRepository: $categoryRepository,
            providerChain: $providerChain
        );

        $context = new PageContext('category', null, 7, null, null, 0);
        $service->send(new ConversationOwner(1, null), null, 'show me more', $context);

        $this->assertStringContainsString('browsing the "Yoga Pants" category', $capturedRequest->getSystemPrompt());
    }

    public function testBuildSystemPromptSwallowsMissingProductAndAddsNoContextLine(): void
    {
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('resolveConversation')->willReturn($this->makeConversation());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));

        $config = $this->createMock(Config::class);
        $config->method('getSystemPrompt')->willReturn('Base prompt.');

        $capturedRequest = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function (LlmRequest $request) use (&$capturedRequest) {
            $capturedRequest = $request;
            return new LlmResponse('reply', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->makeProviderChain($provider, 'openai');

        $service = $this->makeService(
            conversationManager: $conversationManager,
            productRepository: $productRepository,
            config: $config,
            providerChain: $providerChain
        );

        $context = new PageContext('product', 999, null, null, null, 0);
        $result = $service->send(new ConversationOwner(1, null), null, 'is this waterproof?', $context);

        $this->assertSame('reply', $result->getReply());
        $this->assertStringNotContainsString('currently viewing', $capturedRequest->getSystemPrompt());
    }

    private function makeConversation(): Conversation
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(3);
        $conversation->method('getUuid')->willReturn('conv-uuid');
        return $conversation;
    }

    private function makeContext(): PageContext
    {
        return new PageContext('home', null, null, null, null, 0);
    }

    private function makeMessage(string $role, string $content, string $status): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getData')->willReturnMap([
            ['role', null, $role],
            ['content', null, $content],
            ['status', null, $status],
        ]);
        return $message;
    }

    /**
     * @return ProviderChain&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeProviderChain(LlmProviderInterface $provider, string $code)
    {
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('complete')->willReturnCallback(
            static fn(callable $attempt) => $attempt($provider, $code)
        );
        return $providerChain;
    }

    private function makeService(
        ?ConversationManager $conversationManager = null,
        ?MessageRepository $messageRepository = null,
        ?CustomerSession $customerSession = null,
        ?StoreManagerInterface $storeManager = null,
        ?ResolverInterface $localeResolver = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?CategoryRepositoryInterface $categoryRepository = null,
        ?Config $config = null,
        ?ProviderConfig $providerConfig = null,
        ?ProviderChain $providerChain = null,
        ?CostCalculator $costCalculator = null,
        ?ToolRegistry $toolRegistry = null,
        ?LoggerInterface $logger = null,
        ?LlmRequestInterfaceFactory $llmRequestFactory = null,
        ?ToolContextInterfaceFactory $toolContextFactory = null,
        ?ChatResultInterfaceFactory $chatResultFactory = null
    ): ChatService {
        $defaultStore = $this->getMockBuilder(Store::class)->disableOriginalConstructor()->getMock();
        $defaultStore->method('getId')->willReturn(1);
        $defaultStore->method('getWebsiteId')->willReturn(1);
        $defaultStore->method('getName')->willReturn('Test Store');
        $currency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currency->method('getCode')->willReturn('USD');
        $defaultStore->method('getCurrentCurrency')->willReturn($currency);
        $defaultStoreManager = $this->createMock(StoreManagerInterface::class);
        $defaultStoreManager->method('getStore')->willReturn($defaultStore);

        $defaultProviderConfig = $this->createMock(ProviderConfig::class);
        $defaultProviderConfig->method('getModel')->willReturn('gpt-4o');
        $defaultProviderConfig->method('getTemperature')->willReturn(0.7);
        $defaultProviderConfig->method('getMaxTokens')->willReturn(500);

        $defaultToolRegistry = $this->createMock(ToolRegistry::class);
        $defaultToolRegistry->method('getDefinitions')->willReturn([]);

        $defaultConfig = $this->createMock(Config::class);
        $defaultConfig->method('getSystemPrompt')->willReturn('You are a helpful assistant.');

        $defaultConversationManager = $this->createMock(ConversationManager::class);
        $defaultConversationManager->method('resolveConversation')->willReturn($this->makeConversation());
        $defaultConversationManager->method('addAssistantMessage')->willReturn($this->createMock(Message::class));

        $defaultLlmRequestFactory = $this->createMock(LlmRequestInterfaceFactory::class);
        $defaultLlmRequestFactory->method('create')->willReturnCallback(
            static fn (array $data) => new LlmRequest(
                $data['systemPrompt'],
                $data['messages'],
                $data['model'],
                $data['temperature'],
                $data['maxTokens'],
                $data['tools'] ?? []
            )
        );

        $defaultToolContextFactory = $this->createMock(ToolContextInterfaceFactory::class);
        $defaultToolContextFactory->method('create')->willReturnCallback(
            static fn (array $data) => new ToolContext(
                $data['storeId'],
                $data['locale'],
                $data['currencyCode'],
                $data['productId'],
                $data['categoryId'],
                $data['customerGroupId'] ?? 0,
                $data['websiteId'] ?? 0
            )
        );

        $defaultChatResultFactory = $this->createMock(ChatResultInterfaceFactory::class);
        $defaultChatResultFactory->method('create')->willReturnCallback(
            static fn (array $data) => new ChatResult($data['conversationUuid'], $data['reply'])
        );

        return new ChatService(
            $conversationManager ?? $defaultConversationManager,
            $messageRepository ?? $this->createMock(MessageRepository::class),
            $customerSession ?? $this->createMock(CustomerSession::class),
            $storeManager ?? $defaultStoreManager,
            $localeResolver ?? $this->createMock(ResolverInterface::class),
            $productRepository ?? $this->createMock(ProductRepositoryInterface::class),
            $categoryRepository ?? $this->createMock(CategoryRepositoryInterface::class),
            $config ?? $defaultConfig,
            $providerConfig ?? $defaultProviderConfig,
            $providerChain ?? $this->createMock(ProviderChain::class),
            $costCalculator ?? $this->createMock(CostCalculator::class),
            $toolRegistry ?? $defaultToolRegistry,
            $logger ?? $this->createMock(LoggerInterface::class),
            $llmRequestFactory ?? $defaultLlmRequestFactory,
            $toolContextFactory ?? $defaultToolContextFactory,
            $chatResultFactory ?? $defaultChatResultFactory
        );
    }
}
