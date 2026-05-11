<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Dispatch;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Override;
use Throwable;

use function hrtime;
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
    private const ERROR_STATUS_THRESHOLD = 400;
    private const NANOS_PER_MILLI = 1_000_000;

    public function __construct(
        private ResourceInterface $resource,
        private ToolRegistryInterface $registry,
        private ToolCallObserverInterface $observer,
    ) {
    }

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        $startedAt = hrtime(true);
        $result = $this->resolve($toolCall);
        $durationMs = (hrtime(true) - $startedAt) / self::NANOS_PER_MILLI;
        $this->observer->observe($toolCall, $result, $durationMs);

        return $result;
    }

    private function resolve(ToolCall $toolCall): ToolResult
    {
        $mapping = $this->registry->get($toolCall->name);
        if ($mapping === null) {
            return ToolResult::error(
                $toolCall->id,
                sprintf('Unknown tool: %s', $toolCall->name),
            );
        }

        try {
            $ro = $this->invokeResource(
                $mapping->resourceUri,
                $mapping->method,
                $toolCall->input,
            );

            if ($ro->code >= self::ERROR_STATUS_THRESHOLD) {
                $content = json_encode($ro->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return ToolResult::error(
                    $toolCall->id,
                    sprintf('%d: %s', $ro->code, $content),
                );
            }

            /** @var mixed $body */
            $body = $ro->body;
            if ($mapping->filter !== null) {
                $filterClass = $mapping->filter;
                /** @var mixed $body */
                $body = (new $filterClass())($body);
            }

            $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return ToolResult::success($toolCall->id, $content);
        } catch (Throwable $e) {
            return ToolResult::error(
                $toolCall->id,
                sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }
    }

    /** @param array<string, mixed> $input */
    private function invokeResource(string $uri, string $method, array $input): ResourceObject
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

        return $ro;
    }
}
