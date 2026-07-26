<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Analytics;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Analytics\TopicClassifier;
use Yu\AiChat\Model\SystemTaskRepository;
use Yu\AiLlm\Api\Data\LlmRequestInterface;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\LlmResponse;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;

class TopicClassifierTest extends TestCase
{
    public function testRunReturnsZeroWhenNoProviderIsActive(): void
    {
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn([]);

        $classifier = $this->makeClassifier(providerChain: $providerChain);

        $this->assertSame(0, $classifier->run());
    }

    public function testRunRecordsSkippedSystemTaskWhenNoProviderIsActive(): void
    {
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn([]);
        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'classify_topics',
            'status' => SystemTaskRepository::STATUS_SKIPPED,
        ]);

        $classifier = $this->makeClassifier(providerChain: $providerChain, systemTaskRepository: $systemTaskRepository);

        $classifier->run();
    }

    public function testRunClassifiesEachPendingConversationFromTheParsedResponse(): void
    {
        $connection = $this->makeConnection([5 => 'Where is my order?', 8 => 'Do you ship to Canada?']);
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $bind) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(
            new LlmResponse('{"5":"shipping","8":"shipping"}', 1, 1, 'gpt-4o')
        );
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $classifier = $this->makeClassifier($connection, $providerChain);

        $this->assertSame(2, $classifier->run());
        $this->assertSame(['topic' => 'shipping'], $updates[0]);
        $this->assertSame(['topic' => 'shipping'], $updates[1]);
    }

    public function testRunRecordsSuccessSystemTaskWithAccumulatedTokensAndCost(): void
    {
        $connection = $this->makeConnection([5 => 'Where is my order?', 8 => 'Do you ship to Canada?']);
        $connection->method('update')->willReturn(1);

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(
            new LlmResponse('{"5":"shipping","8":"shipping"}', 10, 4, 'gpt-4o')
        );
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $costCalculator = $this->createMock(CostCalculator::class);
        $costCalculator->method('calculate')->with('openai', 10, 4)->willReturn(0.002);

        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'classify_topics',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 10,
            'completion_tokens' => 4,
            'cost' => 0.002,
            'items_processed' => 2,
            'status' => SystemTaskRepository::STATUS_SUCCESS,
        ]);

        $classifier = $this->makeClassifier($connection, $providerChain, costCalculator: $costCalculator, systemTaskRepository: $systemTaskRepository);

        $classifier->run();
    }

    public function testRunFallsBackToOtherWhenModelOmitsAConversationId(): void
    {
        $connection = $this->makeConnection([5 => 'first message']);
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $bind) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('{}', 1, 1, 'gpt-4o'));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $classifier = $this->makeClassifier($connection, $providerChain);
        $classifier->run();

        $this->assertSame(['topic' => 'other'], $updates[0]);
    }

    public function testRunFallsBackToOtherWhenModelReturnsATopicNotInTheAllowedList(): void
    {
        $connection = $this->makeConnection([5 => 'first message']);
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $bind) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('{"5":"made_up_topic"}', 1, 1, 'gpt-4o'));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $classifier = $this->makeClassifier($connection, $providerChain);
        $classifier->run();

        $this->assertSame(['topic' => 'other'], $updates[0]);
    }

    public function testRunParsesJsonEvenWhenWrappedInMarkdownFences(): void
    {
        $connection = $this->makeConnection([5 => 'first message']);
        $updates = [];
        $connection->method('update')->willReturnCallback(function ($table, $bind) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(
            new LlmResponse("Here you go:\n```json\n{\"5\":\"warranty\"}\n```", 1, 1, 'gpt-4o')
        );
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $classifier = $this->makeClassifier($connection, $providerChain);
        $classifier->run();

        $this->assertSame(['topic' => 'warranty'], $updates[0]);
    }

    public function testRunStopsAndReturnsPartialCountOnProviderFailure(): void
    {
        $connection = $this->makeConnection([5 => 'first message']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('down'));

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willThrowException(new LlmProviderException('down', true));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $classifier = $this->makeClassifier($connection, $providerChain, $logger);

        $this->assertSame(0, $classifier->run());
    }

    public function testRunRecordsErrorSystemTaskOnProviderFailureWithPartialProgress(): void
    {
        $connection = $this->makeConnection([5 => 'first message', 8 => 'second message']);
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willThrowException(new LlmProviderException('provider is down', true));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'classify_topics',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost' => null,
            'items_processed' => 0,
            'status' => SystemTaskRepository::STATUS_ERROR,
            'error_message' => 'provider is down',
        ]);

        $classifier = $this->makeClassifier($connection, $providerChain, systemTaskRepository: $systemTaskRepository);

        $classifier->run();
    }

    private function makeClassifier(
        ?AdapterInterface $connection = null,
        ?ProviderChain $providerChain = null,
        ?LoggerInterface $logger = null,
        ?CostCalculator $costCalculator = null,
        ?SystemTaskRepository $systemTaskRepository = null
    ): TopicClassifier {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection ?? $this->makeConnection([]));
        $resource->method('getTableName')->willReturnArgument(0);

        $providerConfig = $this->createMock(ProviderConfig::class);
        $providerConfig->method('getModel')->willReturn('gpt-4o');

        $llmRequestFactory = $this->createMock(LlmRequestInterfaceFactory::class);
        $llmRequestFactory->method('create')->willReturn($this->createMock(LlmRequestInterface::class));

        return new TopicClassifier(
            $resource,
            $providerChain ?? $this->createMock(ProviderChain::class),
            $providerConfig,
            $costCalculator ?? $this->createMock(CostCalculator::class),
            $systemTaskRepository ?? $this->createMock(SystemTaskRepository::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $llmRequestFactory
        );
    }

    /**
     * @param array<int, string> $pending conversation_id => first user message
     * @return AdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeConnection(array $pending)
    {
        $select = $this->getMockBuilder(Select::class)->disableOriginalConstructor()->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn($pending);

        return $connection;
    }
}
