<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Analytics;

use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;
use Yu\AiChat\Model\SystemTaskRepository;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;

class InsightsGenerator
{
    private const TASK_NAME = 'generate_insights';
    private const FLAG = 'yu_aichat_insights';
    private const PERIOD_DAYS = 30;
    private const SYSTEM_PROMPT = 'You are an analytics assistant for an e-commerce store owner. From the JSON '
    . 'analytics data of the store\'s AI shopping assistant, write 3-5 short, concrete, actionable insights '
    . 'as a markdown bullet list. Focus on: products customers could not find, notable topic or cost trends, '
    . 'and improvement opportunities. Base every statement ONLY on the data given. No preamble.';

    public function __construct(
        private readonly Reports $reports,
        private readonly ProviderChain $providerChain,
        private readonly ProviderConfig $providerConfig,
        private readonly CostCalculator $costCalculator,
        private readonly SystemTaskRepository $systemTaskRepository,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger,
        private readonly LlmRequestInterfaceFactory $llmRequestFactory
    ) {
    }

    /**
     * @return string
     * @throws \RuntimeException
     */
    public function generate(): string
    {
        $providers = $this->providerChain->getActiveProviders();
        if ($providers === []) {
            $this->systemTaskRepository->record([
                'task' => self::TASK_NAME,
                'status' => SystemTaskRepository::STATUS_SKIPPED,
            ]);
            throw new \RuntimeException('No AI provider is enabled.');
        }
        $code = array_key_first($providers);
        $model = $this->providerConfig->getModel($code);

        $period = (string)self::PERIOD_DAYS;
        $current = $this->reports->getTotals($period);
        $double = $this->reports->getTotals((string)(self::PERIOD_DAYS * 2));
        $data = [
            'period_days' => self::PERIOD_DAYS,
            'totals' => $current,
            'previous_period_totals' => [
                'conversations' => max(0, $double['conversations'] - $current['conversations']),
                'user_messages' => max(0, $double['user_messages'] - $current['user_messages']),
                'total_cost' => round(max(0, $double['total_cost'] - $current['total_cost']), 4),
            ],
            'topics' => $this->reports->getTopics($period),
            'missing_products' => $this->reports->getMissingProducts($period),
            'popular_questions' => array_slice($this->reports->getPopularQuestions($period), 0, 5),
            'cost_by_provider' => $this->reports->getCostByProvider($period),
        ];

        $request = $this->llmRequestFactory->create([
            'systemPrompt' => self::SYSTEM_PROMPT,
            'messages' => [['role' => 'user', 'content' => (string)json_encode($data, JSON_UNESCAPED_UNICODE)]],
            'model' => $model,
            'temperature' => 0.15,
            'maxTokens' => 4000,
        ]);
        try {
            $response = $providers[$code]->send($request);
        } catch (LlmProviderException $e) {
            $this->logger->warning('[insights] ' . $e->getMessage());
            $this->systemTaskRepository->record([
                'task' => self::TASK_NAME,
                'provider' => $code,
                'model' => $model,
                'status' => SystemTaskRepository::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('The AI provider failed to generate insights.', 0, $e);
        }

        $this->systemTaskRepository->record([
            'task' => self::TASK_NAME,
            'provider' => $code,
            'model' => $model,
            'prompt_tokens' => $response->getPromptTokens(),
            'completion_tokens' => $response->getCompletionTokens(),
            'cost' => $this->costCalculator->calculate($code, $response->getPromptTokens(),
                $response->getCompletionTokens()),
            'status' => SystemTaskRepository::STATUS_SUCCESS,
        ]);

        $this->flagManager->saveFlag(self::FLAG, [
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'period_days' => self::PERIOD_DAYS,
            'text' => $response->getText(),
        ]);
        return $response->getText();
    }

    /**
     * @return array{generated_at: string, period_days: int, text: string}|null
     */
    public function getStored(): ?array
    {
        $data = $this->flagManager->getFlagData(self::FLAG);
        return is_array($data) ? $data : null;
    }
}
