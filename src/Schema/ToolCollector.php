<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use Override;

use function ltrim;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Collects tools from resource classes and registers them
 */
final readonly class ToolCollector implements ToolCollectorInterface
{
    public function __construct(
        private SchemaConverterInterface $converter,
        private ToolRegistryInterface $registry,
    ) {
    }

    /**
     * Collect tools from a resource class
     *
     * @param class-string<ResourceObject> $resourceClass
     *
     * @return list<Tool>
     */
    #[Override]
    public function collect(string $resourceClass, string $resourcePath): array
    {
        $tools = $this->converter->convert($resourceClass, $resourcePath);

        foreach ($tools as $tool) {
            $method = $this->extractMethodFromToolName($tool->name, $resourcePath);
            $this->registry->register($tool->name, ltrim($resourcePath, '/'), $method);
        }

        return $tools;
    }

    /**
     * Collect tools from multiple resource classes
     *
     * @param array<class-string<ResourceObject>, string> $resources Map of class => path
     *
     * @return list<Tool>
     */
    #[Override]
    public function collectAll(array $resources): array
    {
        $allTools = [];
        foreach ($resources as $resourceClass => $resourcePath) {
            $tools = $this->collect($resourceClass, $resourcePath);
            foreach ($tools as $tool) {
                $allTools[] = $tool;
            }
        }

        return $allTools;
    }

    private function extractMethodFromToolName(string $toolName, string $resourcePath): string
    {
        $pathPart = str_replace(['/', '-'], '_', trim($resourcePath, '/'));
        $prefix = $pathPart . '_';

        if (str_starts_with($toolName, $prefix)) {
            return substr($toolName, strlen($prefix));
        }

        // Custom tool name - try to infer from common suffixes
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (str_ends_with($toolName, '_' . $method)) {
                return $method;
            }
        }

        return 'get';
    }
}
