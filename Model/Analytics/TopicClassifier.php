<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Analytics;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Yu\AiChat\Model\SystemTaskRepository;
use Yu\AiLlm\Api\Data\LlmRequestInterfaceFactory;
use Yu\AiLlm\Model\CostCalculator;
use Yu\AiLlm\Model\LlmProviderException;
use Yu\AiLlm\Model\ProviderChain;
use Yu\AiLlm\Model\ProviderConfig;

class TopicClassifier
{
    private const TASK_NAME = 'classify_topics';
    private const BATCH_SIZE = 20;
    private const MAX_PER_RUN = 200;
    private const SYSTEM_PROMPT = 'You classify customer chat conversations from an online store into topics. '
        . 'Allowed topics: %s. Respond with ONLY a JSON object mapping each conversation id to one topic, '
        . 'e.g. {"12":"shipping","15":"product_search"}. No explanations, no markdown.';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProviderChain $providerChain,
        private readonly ProviderConfig $providerConfig,
        private readonly CostCalculator $costCalculator,
        private readonly SystemTaskRepository $systemTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly LlmRequestInterfaceFactory $llmRequestFactory
    ) {
    }

    /**
     * @return int
     */
    public function run(): int
    {
        $providers = $this->providerChain->getActiveProviders();
        if ($providers === []) {
            $this->systemTaskRepository->record([
                'task' => self::TASK_NAME,
                'status' => SystemTaskRepository::STATUS_SKIPPED,
            ]);
            return 0;
        }
        $code = array_key_first($providers);
        $provider = $providers[$code];
        $model = $this->providerConfig->getModel($code);

        $pending = $this->fetchPending();
        $classified = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        foreach (array_chunk($pending, self::BATCH_SIZE, true) as $batch) {
            $lines = [];
            foreach ($batch as $id => $firstMessage) {
                $lines[] = $id . ': ' . mb_substr(str_replace("\n", ' ', $firstMessage), 0, 200);
            }
            $request = $this->llmRequestFactory->create([
                'systemPrompt' => sprintf(self::SYSTEM_PROMPT, implode(', ', Topics::ALL)),
                'messages' => [['role' => 'user', 'content' => implode("\n", $lines)]],
                'model' => $model,
                'temperature' => 0.0,
                'maxTokens' => 1000,
            ]);
            try {
                $response = $provider->send($request);
            } catch (LlmProviderException $e) {
                $this->logger->warning('[classifier] ' . $e->getMessage());
                $this->systemTaskRepository->record([
                    'task' => self::TASK_NAME,
                    'provider' => $code,
                    'model' => $model,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'cost' => $this->costCalculator->calculate($code, $promptTokens, $completionTokens),
                    'items_processed' => $classified,
                    'status' => SystemTaskRepository::STATUS_ERROR,
                    'error_message' => $e->getMessage(),
                ]);
                return $classified;
            }
            $promptTokens += $response->getPromptTokens();
            $completionTokens += $response->getCompletionTokens();
            $map = $this->parseJson($response->getText());
            foreach (array_keys($batch) as $id) {
                $topic = (string)($map[(string)$id] ?? $map[$id] ?? 'other');
                if (!in_array($topic, Topics::ALL, true)) {
                    $topic = 'other';
                }
                $this->saveTopic((int)$id, $topic);
                $classified++;
            }
        }
        $this->systemTaskRepository->record([
            'task' => self::TASK_NAME,
            'provider' => $code,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost' => $this->costCalculator->calculate($code, $promptTokens, $completionTokens),
            'items_processed' => $classified,
            'status' => SystemTaskRepository::STATUS_SUCCESS,
        ]);
        return $classified;
    }

    /**
     * @return array<int, string> conversation_id => first user message
     */
    private function fetchPending(): array
    {
        $conn = $this->resource->getConnection();
        $conv = $this->resource->getTableName('yu_aichat_conversation');
        $msg = $this->resource->getTableName('yu_aichat_message');
        $select = $conn->select()
            ->from(['c' => $conv], ['conversation_id'])
            ->join(
                ['m' => $msg],
                "m.conversation_id = c.conversation_id AND m.role = 'user'",
                ['first_message' => new \Zend_Db_Expr('MIN(m.content)')]
            )
            ->where('c.topic IS NULL')
            // Only conversations that got at least one real reply.
            ->where(sprintf(
                "EXISTS (SELECT 1 FROM %s a WHERE a.conversation_id = c.conversation_id AND a.role = 'assistant' AND a.status = 'complete')",
                $msg
            ))
            ->group('c.conversation_id')
            ->limit(self::MAX_PER_RUN);
        return $conn->fetchPairs($select);
    }

    /**
     * @param int $conversationId
     * @param string $topic
     * @return void
     */
    private function saveTopic(int $conversationId, string $topic): void
    {
        $conn = $this->resource->getConnection();
        $conn->update(
            $this->resource->getTableName('yu_aichat_conversation'),
            ['topic' => $topic],
            ['conversation_id = ?' => $conversationId]
        );
    }

    /**
     * @param string $text
     * @return array<string|int, string>
     */
    private function parseJson(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        return (array)json_decode(substr($text, $start, $end - $start + 1), true);
    }
}
