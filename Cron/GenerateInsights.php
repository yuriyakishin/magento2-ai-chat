<?php
declare(strict_types=1);

namespace Yu\AiChat\Cron;

use Psr\Log\LoggerInterface;
use Yu\AiChat\Model\Analytics\InsightsGenerator;
use Yu\AiChat\Model\Config;

class GenerateInsights
{
    public function __construct(
        private readonly Config $config,
        private readonly InsightsGenerator $generator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isInsightsEnabled()) {
            return;
        }
        try {
            $this->generator->generate();
            $this->logger->info('[insights] regenerated');
        } catch (\RuntimeException $e) {
            $this->logger->warning('[insights] skipped: ' . $e->getMessage());
        }
    }
}
