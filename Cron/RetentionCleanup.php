<?php
declare(strict_types=1);

namespace Yu\AiChat\Cron;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Yu\AiChat\Model\Config;

class RetentionCleanup
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $days = $this->config->getRetentionDays();
        if ($days <= 0) {
            return;
        }
        $conn = $this->resource->getConnection();
        // Messages cascade via the FK.
        $deleted = $conn->delete(
            $this->resource->getTableName('yu_aichat_conversation'),
            ['updated_at < ?' => (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s')]
        );
        if ($deleted > 0) {
            $this->logger->info(sprintf('[retention] deleted %d conversation(s) older than %d days', $deleted, $days));
        }
    }
}
