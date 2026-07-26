<?php
declare(strict_types=1);

namespace Yu\AiChat\Model\Tools;

use Psr\Log\LoggerInterface;
use Yu\AiChat\Api\Data\ToolContextInterface;
use Yu\AiChat\Api\ToolInterface;

/**
 * Tools are registered through di.xml (see etc/di.xml and docs/tools.md);
 * third-party modules append their own entries. Keys are taken from
 * ToolInterface::getName(), not from the di.xml item name.
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $byName = [];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        array $tools = []
    ) {
        foreach ($tools as $tool) {
            $this->byName[$tool->getName()] = $tool;
        }
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function getDefinitions(): array
    {
        $definitions = [];
        foreach ($this->byName as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParametersSchema(),
            ];
        }
        return $definitions;
    }

    /**
     * @param string $name
     * @param array<string, mixed> $args
     * @param ToolContextInterface $context
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args, ToolContextInterface $context): array
    {
        $tool = $this->byName[$name] ?? null;
        if ($tool === null) {
            return ['error' => sprintf('Unknown tool "%s"', $name)];
        }
        try {
            return $tool->execute($args, $context);
        } catch (\Throwable $e) {
            // A tool crash must not kill the whole chat turn.
            $this->logger->error(sprintf('Tool %s failed: %s', $name, $e->getMessage()));
            return ['error' => 'The tool failed to execute. Answer without this data and apologize.'];
        }
    }
}
