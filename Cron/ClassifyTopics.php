<?php
declare(strict_types=1);

namespace Yu\AiChat\Cron;

use Psr\Log\LoggerInterface;
use Yu\AiChat\Model\Analytics\TopicClassifier;
use Yu\AiChat\Model\Config;

class ClassifyTopics
{
    public function __construct(
        private readonly Config $config,
        private readonly TopicClassifier $classifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isClassificationEnabled()) {
            return;
        }
        $count = $this->classifier->run();
        if ($count > 0) {
            $this->logger->info(sprintf('[classifier] classified %d conversation(s)', $count));
        }
    }
}
