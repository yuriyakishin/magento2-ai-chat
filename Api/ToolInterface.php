<?php
declare(strict_types=1);

namespace Yu\AiChat\Api;

use Yu\AiChat\Api\Data\ToolContextInterface;

/**
 * One function the LLM may call. Implementations must be side-effect free
 * (read-only catalog/config access) and must return "no results" / "not
 * found" as result payloads, never as exceptions — empty results are
 * analytics data, not errors.
 */
interface ToolInterface
{
    /**
     * @return string
     */
    public function getName(): string;

    /**
     * Written for the model: say WHEN to call the tool, not only what it does.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Provider-neutral JSON schema of the arguments object.
     *
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array;

    /**
     * @param array<string, mixed> $args
     * @param ToolContextInterface $context
     * @return array<string, mixed> JSON-serializable result
     */
    public function execute(array $args, ToolContextInterface $context): array;
}
