<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Cron\RetentionCleanup;
use Yu\AiChat\Model\Config;

class RetentionCleanupTest extends TestCase
{
    public function testExecuteDoesNothingWhenRetentionIsZeroOrLess(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getRetentionDays')->willReturn(0);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $cleanup = new RetentionCleanup($config, $resource, $this->createMock(LoggerInterface::class));

        $cleanup->execute();
    }

    public function testExecuteDeletesConversationsOlderThanRetentionDaysCutoff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getRetentionDays')->willReturn(30);

        $conn = $this->createMock(AdapterInterface::class);
        $capturedCutoff = null;
        $conn->expects($this->once())
            ->method('delete')
            ->with('yu_aichat_conversation', $this->callback(
                function (array $where) use (&$capturedCutoff): bool {
                    $capturedCutoff = $where['updated_at < ?'] ?? null;
                    return array_key_first($where) === 'updated_at < ?';
                }
            ))
            ->willReturn(3);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->with('yu_aichat_conversation')->willReturn('yu_aichat_conversation');

        $cleanup = new RetentionCleanup($config, $resource, $this->createMock(LoggerInterface::class));
        $cleanup->execute();

        // Cutoff should be ~30 days before now; allow a few seconds of test-run slack.
        $expected = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $this->assertNotNull($capturedCutoff);
        $delta = abs(strtotime($expected) - strtotime($capturedCutoff));
        $this->assertLessThan(5, $delta, "Cutoff $capturedCutoff was not within 5 seconds of expected $expected");
    }

    public function testExecuteLogsWhenRowsWereDeleted(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getRetentionDays')->willReturn(30);
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('delete')->willReturn(4);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturn('yu_aichat_conversation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('deleted 4 conversation(s)'));

        $cleanup = new RetentionCleanup($config, $resource, $logger);
        $cleanup->execute();
    }

    public function testExecuteDoesNotLogWhenNoRowsWereDeleted(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getRetentionDays')->willReturn(30);
        $conn = $this->createMock(AdapterInterface::class);
        $conn->method('delete')->willReturn(0);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturn('yu_aichat_conversation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $cleanup = new RetentionCleanup($config, $resource, $logger);
        $cleanup->execute();
    }
}
