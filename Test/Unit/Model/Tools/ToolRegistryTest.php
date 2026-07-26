<?php

declare(strict_types=1);

namespace Yu\AiChat\Test\Unit\Model\Tools;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Yu\AiChat\Api\ToolInterface;
use Yu\AiChat\Model\Tools\ToolContext;
use Yu\AiChat\Model\Tools\ToolRegistry;

class ToolRegistryTest extends TestCase
{
    public function testGetDefinitionsReturnsNameDescriptionAndSchemaForEachTool(): void
    {
        $search = $this->makeTool('search_products', 'Search the catalog', ['type' => 'object']);
        $registry = new ToolRegistry($this->createMock(LoggerInterface::class), [$search]);

        $this->assertSame(
            [['name' => 'search_products', 'description' => 'Search the catalog', 'parameters' => ['type' => 'object']]],
            $registry->getDefinitions()
        );
    }

    public function testToolsAreKeyedByGetNameNotDiXmlItemName(): void
    {
        // The array key passed to the constructor is irrelevant; only
        // ToolInterface::getName() decides the lookup key used by execute().
        $tool = $this->makeTool('real_name', 'desc', []);
        $registry = new ToolRegistry($this->createMock(LoggerInterface::class), ['some_di_xml_key' => $tool]);

        $result = $registry->execute('real_name', [], $this->createMock(ToolContext::class));

        $this->assertSame(['ok' => true], $result);
    }

    public function testExecuteReturnsErrorForUnknownToolName(): void
    {
        $registry = new ToolRegistry($this->createMock(LoggerInterface::class), []);

        $result = $registry->execute('nonexistent', [], $this->createMock(ToolContext::class));

        $this->assertSame(['error' => 'Unknown tool "nonexistent"'], $result);
    }

    public function testExecutePassesArgsAndContextThroughToTheTool(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('search_products');
        $context = $this->createMock(ToolContext::class);
        $tool->expects($this->once())
            ->method('execute')
            ->with(['query' => 'shoes'], $context)
            ->willReturn(['count' => 3]);

        $registry = new ToolRegistry($this->createMock(LoggerInterface::class), [$tool]);

        $this->assertSame(['count' => 3], $registry->execute('search_products', ['query' => 'shoes'], $context));
    }

    public function testExecuteCatchesToolExceptionsAndLogsWithoutCrashingTheChatTurn(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('search_products');
        $tool->method('execute')->willThrowException(new \RuntimeException('DB is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('search_products'));

        $registry = new ToolRegistry($logger, [$tool]);

        $result = $registry->execute('search_products', [], $this->createMock(ToolContext::class));

        $this->assertSame(
            ['error' => 'The tool failed to execute. Answer without this data and apologize.'],
            $result
        );
    }

    private function makeTool(string $name, string $description, array $schema): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getDescription')->willReturn($description);
        $tool->method('getParametersSchema')->willReturn($schema);
        $tool->method('execute')->willReturn(['ok' => true]);
        return $tool;
    }
}
