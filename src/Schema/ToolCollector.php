<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\FactoryInterface;
use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Dispatch\ToolRegistryInterface;
use Override;

use function ltrim;
use function parse_url;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const PHP_URL_PATH;

/**
 * Collects tools from resource URIs and registers them
 */
final readonly class ToolCollector implements ToolCollectorInterface
{
    public function __construct(
        private SchemaConverterInterface $converter,
        private ToolRegistryInterface $registry,
        private FactoryInterface $resourceFactory,
    ) {
    }

    /**
     * Collect tools from resource URIs
     *
     * @param list<string> $uris
     *
     * @return list<Tool>
     */
    #[Override]
    public function collect(array $uris): array
    {
        $allTools = [];
        foreach ($uris as $uri) {
            $tools = $this->collectFromUri($uri);
            foreach ($tools as $tool) {
                $allTools[] = $tool;
            }
        }

        return $allTools;
    }

    /** @return list<Tool> */
    private function collectFromUri(string $uri): array
    {
        $ro = $this->resourceFactory->newInstance($uri);
        $resourceClass = $ro::class;
        $resourcePath = $this->extractPath($uri);

        $tools = $this->converter->convert($resourceClass, $resourcePath);

        foreach ($tools as $tool) {
            $method = $this->extractMethodFromToolName($tool->name, $resourcePath);
            $this->registry->register($tool->name, $uri, $method);
        }

        return $tools;
    }

    private function extractPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return '/'; // @codeCoverageIgnore
        }

        return '/' . ltrim($path, '/');
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
