<?php
declare(strict_types=1);

namespace Yu\AiChat\Model;

use Yu\AiChat\Model\ResourceModel\SystemTask as SystemTaskResource;

/**
 * Records one row per run of a background/system task (e.g. topic
 * classification, insight generation) so LLM usage that happens outside a
 * customer conversation is still visible in the dashboard - unlike
 * yu_aichat_message, this carries no conversation_id and isn't subject to
 * conversation retention cleanup.
 */
class SystemTaskRepository
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        private readonly SystemTaskResource $resource,
        private readonly SystemTaskFactory $systemTaskFactory
    ) {
    }

    /**
     * @param array<string, mixed> $data task, status, and optionally provider,
     *        model, prompt_tokens, completion_tokens, cost, items_processed,
     *        error_message
     * @return void
     */
    public function record(array $data): void
    {
        $task = $this->systemTaskFactory->create();
        $task->setData($data);
        $this->resource->save($task);
    }
}
