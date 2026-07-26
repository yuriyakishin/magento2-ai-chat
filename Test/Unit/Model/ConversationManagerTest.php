<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Conversation;
use Yu\AiChat\Model\ConversationFactory;
use Yu\AiChat\Model\ConversationManager;
use Yu\AiChat\Model\ConversationOwner;
use Yu\AiChat\Model\ConversationRepository;
use Yu\AiChat\Model\Message;
use Yu\AiChat\Model\MessageFactory;
use Yu\AiChat\Model\MessageRepository;
use Yu\AiChat\Model\PageContext;

class ConversationManagerTest extends TestCase
{
    public function testResolveConversationLoadsExistingWhenUuidGiven(): void
    {
        $existing = $this->createMock(Conversation::class);
        $conversationRepository = $this->createMock(ConversationRepository::class);
        $conversationRepository->expects($this->once())
            ->method('getByUuidForOwner')
            ->with('existing-uuid', $this->isInstanceOf(ConversationOwner::class))
            ->willReturn($existing);
        $conversationRepository->expects($this->never())->method('save');

        $manager = $this->makeManager(conversationRepository: $conversationRepository);

        $this->assertSame(
            $existing,
            $manager->resolveConversation(new ConversationOwner(1, null), 'existing-uuid', 'hello')
        );
    }

    public function testResolveConversationCreatesNewOneWhenUuidIsNull(): void
    {
        $created = $this->createMock(Conversation::class);
        $created->expects($this->once())->method('setData')->with($this->callback(
            static function (array $data): bool {
                return $data['uuid'] === 'generated-uuid'
                    && $data['customer_id'] === 7
                    && $data['guest_token'] === null
                    && $data['channel'] === 'web'
                    && $data['store_id'] === 1
                    && $data['locale'] === 'en_US'
                    && $data['title'] === 'hello there';
            }
        ));

        $conversationFactory = $this->createMock(ConversationFactory::class);
        $conversationFactory->method('create')->willReturn($created);

        $conversationRepository = $this->createMock(ConversationRepository::class);
        $conversationRepository->method('save')->willReturnArgument(0);

        $identityGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $identityGenerator->method('generateId')->willReturn('generated-uuid');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $localeResolver = $this->createMock(ResolverInterface::class);
        $localeResolver->method('getLocale')->willReturn('en_US');

        $manager = $this->makeManager(
            conversationFactory: $conversationFactory,
            conversationRepository: $conversationRepository,
            identityGenerator: $identityGenerator,
            storeManager: $storeManager,
            localeResolver: $localeResolver
        );

        $result = $manager->resolveConversation(new ConversationOwner(7, null), null, 'hello there');

        $this->assertSame($created, $result);
    }

    public function testResolveConversationTruncatesLongFirstMessageToTitleLength(): void
    {
        $longMessage = str_repeat('a', 300);
        $created = $this->createMock(Conversation::class);
        $created->expects($this->once())->method('setData')->with($this->callback(
            static function (array $data) use ($longMessage): bool {
                return $data['title'] === mb_substr($longMessage, 0, 255);
            }
        ));

        $conversationFactory = $this->createMock(ConversationFactory::class);
        $conversationFactory->method('create')->willReturn($created);
        $conversationRepository = $this->createMock(ConversationRepository::class);
        $conversationRepository->method('save')->willReturnArgument(0);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $manager = $this->makeManager(
            conversationFactory: $conversationFactory,
            conversationRepository: $conversationRepository,
            storeManager: $storeManager
        );

        $manager->resolveConversation(new ConversationOwner(1, null), null, $longMessage);
    }

    public function testAddUserMessagePersistsPageContextFields(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(3);
        $context = new PageContext('product', 55, null, 'https://example.test/p', 'https://ref.test', 0);

        $message = $this->createMock(Message::class);
        $message->expects($this->once())->method('setData')->with([
            'conversation_id' => 3,
            'role' => 'user',
            'content' => 'hi there',
            'status' => 'complete',
            'page_type' => 'product',
            'product_id' => 55,
            'category_id' => null,
            'url' => 'https://example.test/p',
            'referrer' => 'https://ref.test',
            'customer_group_id' => 0,
        ]);
        $messageFactory = $this->createMock(MessageFactory::class);
        $messageFactory->method('create')->willReturn($message);
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('save')->willReturnArgument(0);

        $manager = $this->makeManager(messageFactory: $messageFactory, messageRepository: $messageRepository);

        $this->assertSame($message, $manager->addUserMessage($conversation, 'hi there', $context));
    }

    public function testAddAssistantMessageMergesMetaWithoutOverridingCoreFields(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(3);

        $message = $this->createMock(Message::class);
        $message->expects($this->once())->method('setData')->with([
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'conversation_id' => 3,
            'role' => 'assistant',
            'content' => 'the reply',
            'status' => 'complete',
        ]);
        $messageFactory = $this->createMock(MessageFactory::class);
        $messageFactory->method('create')->willReturn($message);
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('save')->willReturnArgument(0);

        $manager = $this->makeManager(messageFactory: $messageFactory, messageRepository: $messageRepository);

        $manager->addAssistantMessage($conversation, 'the reply', ['prompt_tokens' => 10, 'completion_tokens' => 20]);
    }

    public function testAddAssistantMessageMetaCanOverrideStatus(): void
    {
        // $meta + [core] is a PHP array union: $meta's keys win on overlap.
        // ChatService relies on exactly this to record the error path as
        // ['status' => 'error', ...] instead of the default 'complete'.
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(3);

        $message = $this->createMock(Message::class);
        $message->expects($this->once())->method('setData')->with($this->callback(
            static function (array $data): bool {
                return $data['status'] === 'error' && $data['role'] === 'assistant';
            }
        ));
        $messageFactory = $this->createMock(MessageFactory::class);
        $messageFactory->method('create')->willReturn($message);
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('save')->willReturnArgument(0);

        $manager = $this->makeManager(messageFactory: $messageFactory, messageRepository: $messageRepository);

        $manager->addAssistantMessage($conversation, 'the reply', ['status' => 'error']);
    }

    public function testAddToolMessageEncodesToolNameArgumentsAndResultAsJson(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getId')->willReturn(3);
        $context = new PageContext('product', 55, null, null, null, 0);

        $message = $this->createMock(Message::class);
        $message->expects($this->once())->method('setData')->with($this->callback(
            static function (array $data): bool {
                $decoded = json_decode($data['content'], true);
                return $data['role'] === 'tool'
                    && $decoded === [
                        'tool' => 'search_products',
                        'arguments' => ['query' => 'shoes'],
                        'result' => ['count' => 2],
                    ];
            }
        ));
        $messageFactory = $this->createMock(MessageFactory::class);
        $messageFactory->method('create')->willReturn($message);
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('save')->willReturnArgument(0);

        $manager = $this->makeManager(messageFactory: $messageFactory, messageRepository: $messageRepository);

        $manager->addToolMessage(
            $conversation,
            'search_products',
            ['query' => 'shoes'],
            ['count' => 2],
            $context
        );
    }

    public function testTouchSetsProviderAndModelWhenGiven(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->expects($this->exactly(2))->method('setData')->withConsecutive(
            ['provider', 'openai'],
            ['model', 'gpt-4o']
        );
        $conversation->expects($this->once())->method('setDataChanges')->with(true);
        $conversationRepository = $this->createMock(ConversationRepository::class);
        $conversationRepository->expects($this->once())->method('save')->with($conversation);

        $manager = $this->makeManager(conversationRepository: $conversationRepository);

        $manager->touch($conversation, 'openai', 'gpt-4o');
    }

    public function testTouchSkipsProviderAndModelWhenNotGiven(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $conversation->expects($this->never())->method('setData');
        $conversation->expects($this->once())->method('setDataChanges')->with(true);
        $conversationRepository = $this->createMock(ConversationRepository::class);
        $conversationRepository->expects($this->once())->method('save')->with($conversation);

        $manager = $this->makeManager(conversationRepository: $conversationRepository);

        $manager->touch($conversation);
    }

    private function makeManager(
        ?ConversationFactory $conversationFactory = null,
        ?MessageFactory $messageFactory = null,
        ?ConversationRepository $conversationRepository = null,
        ?MessageRepository $messageRepository = null,
        ?IdentityGeneratorInterface $identityGenerator = null,
        ?StoreManagerInterface $storeManager = null,
        ?ResolverInterface $localeResolver = null
    ): ConversationManager {
        return new ConversationManager(
            $conversationFactory ?? $this->createMock(ConversationFactory::class),
            $messageFactory ?? $this->createMock(MessageFactory::class),
            $conversationRepository ?? $this->createMock(ConversationRepository::class),
            $messageRepository ?? $this->createMock(MessageRepository::class),
            $identityGenerator ?? $this->createMock(IdentityGeneratorInterface::class),
            $storeManager ?? $this->createMock(StoreManagerInterface::class),
            $localeResolver ?? $this->createMock(ResolverInterface::class)
        );
    }
}
