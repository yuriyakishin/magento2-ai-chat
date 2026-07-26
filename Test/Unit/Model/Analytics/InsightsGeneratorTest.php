<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Analytics;

use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Model\Analytics\InsightsGenerator;
use Yu\AiChat\Model\Analytics\Reports;
use Yu\AiChat\Model\SystemTaskRepository;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Api\LlmProviderInterface;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\LlmRequest;
use Yu\AiLlm\Model\LlmResponse;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;

class InsightsGeneratorTest extends TestCase
{
    public function testGenerateThrowsWhenNoProviderIsActive(): void
    {
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn([]);

        $generator = $this->makeGenerator(providerChain: $providerChain);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No AI provider is enabled.');

        $generator->generate();
    }

    public function testGenerateRecordsSkippedSystemTaskWhenNoProviderIsActive(): void
    {
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn([]);
        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'generate_insights',
            'status' => SystemTaskRepository::STATUS_SKIPPED,
        ]);

        $generator = $this->makeGenerator(providerChain: $providerChain, systemTaskRepository: $systemTaskRepository);

        try {
            $generator->generate();
        } catch (\RuntimeException $e) {
            // Expected - covered by testGenerateThrowsWhenNoProviderIsActive.
        }
    }

    public function testGenerateComputesPreviousPeriodDeltaClampedToZero(): void
    {
        // previous_period_totals = double-period totals minus current-period
        // totals, floored at 0 so a falling total never reports negative.
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturnMap([
            ['30', ['conversations' => 10, 'user_messages' => 40, 'avg_latency_ms' => 500, 'total_tokens' => 1000, 'total_cost' => 5.0]],
            ['60', ['conversations' => 3, 'user_messages' => 45, 'avg_latency_ms' => 400, 'total_tokens' => 900, 'total_cost' => 4.0]],
        ]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $captured = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function ($request) use (&$captured) {
            $captured = json_decode($request->getMessages()[0]['content'], true);
            return new LlmResponse('insight text', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $generator = $this->makeGenerator($reports, $providerChain);
        $generator->generate();

        // conversations and cost fell (double-period lower than current)
        // so both clamp to 0; user_messages still had headroom (45 - 40 = 5).
        // json_decode renders a whole-number JSON value as int regardless of
        // the PHP float it came from, so the clamped cost decodes as int 0.
        $this->assertSame(0, $captured['previous_period_totals']['conversations']);
        $this->assertSame(5, $captured['previous_period_totals']['user_messages']);
        $this->assertSame(0, $captured['previous_period_totals']['total_cost']);
    }

    public function testGenerateComputesPositivePreviousPeriodDelta(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturnMap([
            ['30', ['conversations' => 10, 'user_messages' => 40, 'avg_latency_ms' => 500, 'total_tokens' => 1000, 'total_cost' => 5.0]],
            ['60', ['conversations' => 25, 'user_messages' => 90, 'avg_latency_ms' => 400, 'total_tokens' => 900, 'total_cost' => 12.5]],
        ]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $captured = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function ($request) use (&$captured) {
            $captured = json_decode($request->getMessages()[0]['content'], true);
            return new LlmResponse('insight text', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $generator = $this->makeGenerator($reports, $providerChain);
        $generator->generate();

        $this->assertSame(15, $captured['previous_period_totals']['conversations']);
        $this->assertSame(50, $captured['previous_period_totals']['user_messages']);
        $this->assertSame(7.5, $captured['previous_period_totals']['total_cost']);
    }

    public function testGenerateLimitsPopularQuestionsToFive(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturn(['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn(['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7']);
        $reports->method('getCostByProvider')->willReturn([]);

        $captured = null;
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturnCallback(function ($request) use (&$captured) {
            $captured = json_decode($request->getMessages()[0]['content'], true);
            return new LlmResponse('insight text', 1, 1, 'gpt-4o');
        });
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $generator = $this->makeGenerator($reports, $providerChain);
        $generator->generate();

        $this->assertCount(5, $captured['popular_questions']);
        $this->assertSame(['q1', 'q2', 'q3', 'q4', 'q5'], $captured['popular_questions']);
    }

    public function testGenerateWrapsProviderFailureAsRuntimeException(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturn(['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willThrowException(new LlmProviderException('down', true));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('down'));

        $generator = $this->makeGenerator($reports, $providerChain, logger: $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The AI provider failed to generate insights.');

        $generator->generate();
    }

    public function testGenerateRecordsErrorSystemTaskOnProviderFailure(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturn(['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willThrowException(new LlmProviderException('provider is down', true));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'generate_insights',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'status' => SystemTaskRepository::STATUS_ERROR,
            'error_message' => 'provider is down',
        ]);

        $generator = $this->makeGenerator($reports, $providerChain, systemTaskRepository: $systemTaskRepository);

        try {
            $generator->generate();
        } catch (\RuntimeException $e) {
            // Expected - covered by testGenerateWrapsProviderFailureAsRuntimeException.
        }
    }

    public function testGenerateSavesGeneratedTextAndPeriodToFlagManager(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturn(['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('- Insight one', 1, 1, 'gpt-4o'));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->once())->method('saveFlag')->with(
            'yu_aichat_insights',
            $this->callback(static fn(array $data): bool => $data['text'] === '- Insight one' && $data['period_days'] === 30)
        );

        $generator = $this->makeGenerator($reports, $providerChain, $flagManager);

        $this->assertSame('- Insight one', $generator->generate());
    }

    public function testGenerateRecordsSuccessSystemTaskWithTokensAndCost(): void
    {
        $reports = $this->createMock(Reports::class);
        $reports->method('getTotals')->willReturn(['conversations' => 0, 'user_messages' => 0, 'avg_latency_ms' => 0, 'total_tokens' => 0, 'total_cost' => 0.0]);
        $reports->method('getTopics')->willReturn([]);
        $reports->method('getMissingProducts')->willReturn([]);
        $reports->method('getPopularQuestions')->willReturn([]);
        $reports->method('getCostByProvider')->willReturn([]);

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('send')->willReturn(new LlmResponse('- Insight one', 300, 120, 'gpt-4o'));
        $providerChain = $this->createMock(ProviderChain::class);
        $providerChain->method('getActiveProviders')->willReturn(['openai' => $provider]);

        $costCalculator = $this->createMock(CostCalculator::class);
        $costCalculator->method('calculate')->with('openai', 300, 120)->willReturn(0.01);

        $systemTaskRepository = $this->createMock(SystemTaskRepository::class);
        $systemTaskRepository->expects($this->once())->method('record')->with([
            'task' => 'generate_insights',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 300,
            'completion_tokens' => 120,
            'cost' => 0.01,
            'status' => SystemTaskRepository::STATUS_SUCCESS,
        ]);

        $generator = $this->makeGenerator($reports, $providerChain, costCalculator: $costCalculator, systemTaskRepository: $systemTaskRepository);

        $generator->generate();
    }

    public function testGetStoredReturnsNullWhenFlagIsUnset(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(null);

        $generator = $this->makeGenerator(flagManager: $flagManager);

        $this->assertNull($generator->getStored());
    }

    public function testGetStoredReturnsStoredArray(): void
    {
        $stored = ['generated_at' => '2026-01-01 00:00:00', 'period_days' => 30, 'text' => 'x'];
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn($stored);

        $generator = $this->makeGenerator(flagManager: $flagManager);

        $this->assertSame($stored, $generator->getStored());
    }

    private function makeGenerator(
        ?Reports $reports = null,
        ?ProviderChain $providerChain = null,
        ?FlagManager $flagManager = null,
        ?LoggerInterface $logger = null,
        ?CostCalculator $costCalculator = null,
        ?SystemTaskRepository $systemTaskRepository = null
    ): InsightsGenerator {
        $providerConfig = $this->createMock(ProviderConfig::class);
        $providerConfig->method('getModel')->willReturn('gpt-4o');

        $llmRequestFactory = $this->createMock(LlmRequestInterfaceFactory::class);
        $llmRequestFactory->method('create')->willReturnCallback(
            static fn (array $data) => new LlmRequest(
                $data['systemPrompt'],
                $data['messages'],
                $data['model'],
                $data['temperature'],
                $data['maxTokens'],
                $data['tools'] ?? []
            )
        );

        return new InsightsGenerator(
            $reports ?? $this->createMock(Reports::class),
            $providerChain ?? $this->createMock(ProviderChain::class),
            $providerConfig,
            $costCalculator ?? $this->createMock(CostCalculator::class),
            $systemTaskRepository ?? $this->createMock(SystemTaskRepository::class),
            $flagManager ?? $this->createMock(FlagManager::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $llmRequestFactory
        );
    }
}
