<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

use function json_encode;
use function sprintf;
use function strtoupper;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Dispatches tool calls to BEAR.Resource
 */
final readonly class Dispatcher implements DispatcherInterface
{
    public function __construct(
        private ResourceInterface $resource,
        private ToolRegistryInterface $registry,
    ) {
    }

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        $mapping = $this->registry->get($toolCall->name);
        if ($mapping === null) {
            return ToolResult::error(
                $toolCall->id,
                sprintf('Unknown tool: %s', $toolCall->name),
            );
        }

        try {
            $result = $this->executeResource(
                $mapping['resourceUri'],
                $mapping['method'],
                $toolCall->input,
            );

            return ToolResult::success($toolCall->id, $result);
        } catch (Throwable $e) {
            return ToolResult::error(
                $toolCall->id,
                sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }
    }

    /** @param array<string, mixed> $input */
    private function executeResource(string $uri, string $method, array $input): string
    {
        $httpMethod = strtoupper($method);

        /** @var ResourceObject $ro */
        $ro = match ($httpMethod) {
            'GET' => $this->resource->get($uri, $input),
            'POST' => $this->resource->post($uri, $input),
            'PUT' => $this->resource->put($uri, $input),
            'PATCH' => $this->resource->patch($uri, $input),
            'DELETE' => $this->resource->delete($uri, $input),
            default => $this->resource->get($uri, $input),
        };

        return json_encode($ro->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
