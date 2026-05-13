<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Dispatch\DispatcherInterface;
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;
use Throwable;

use function array_keys;
use function is_array;
use function is_string;
use function json_encode;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Delegates ask_* tool calls to named subagents
 */
final readonly class AgentDelegator implements DispatcherInterface
{
    private const TOOL_PREFIX = 'ask_';

    public function __construct(
        private AgentPool $pool,
        private DispatcherInterface|null $fallback = null,
    ) {
    }

    /**
     * Ask a named subagent directly.
     *
     * @param array<string, mixed> $context
     */
    public function ask(string $name, string $message, array $context = []): AgentResponse
    {
        $agent = $this->pool->create($name);

        return $agent->run($this->buildMessage($message, $context));
    }

    public function canDispatch(string $toolName): bool
    {
        if (! str_starts_with($toolName, self::TOOL_PREFIX)) {
            return false;
        }

        return $this->pool->has($this->agentNameFromTool($toolName));
    }

    #[Override]
    public function dispatch(ToolCall $toolCall): ToolResult
    {
        if (! str_starts_with($toolCall->name, self::TOOL_PREFIX)) {
            return $this->fallback?->dispatch($toolCall)
                ?? ToolResult::error($toolCall->id, sprintf('Unknown tool: %s', $toolCall->name));
        }

        $agentName = $this->agentNameFromTool($toolCall->name);
        if (! $this->pool->has($agentName)) {
            return $this->fallback?->dispatch($toolCall)
                ?? ToolResult::error($toolCall->id, sprintf('Unknown agent: %s', $agentName));
        }

        $message = $toolCall->input['message'] ?? null;
        if (! is_string($message)) {
            return ToolResult::error($toolCall->id, 'Agent tool input "message" must be a string.');
        }

        $context = $this->contextFromInput($toolCall->input['context'] ?? []);
        if ($context === null) {
            return ToolResult::error($toolCall->id, 'Agent tool input "context" must be an object.');
        }

        try {
            $response = $this->ask($agentName, $message, $context);
        } catch (Throwable $e) {
            return ToolResult::error($toolCall->id, $e::class . ': ' . $e->getMessage());
        }

        if (! $response->completed) {
            return ToolResult::error($toolCall->id, sprintf(
                'Subagent stopped: %s',
                $response->stopReason,
            ));
        }

        return ToolResult::success($toolCall->id, $response->getText());
    }

    private function agentNameFromTool(string $toolName): string
    {
        return substr($toolName, strlen(self::TOOL_PREFIX));
    }

    /** @return array<string, mixed>|null */
    private function contextFromInput(mixed $context): array|null
    {
        if (! is_array($context)) {
            return null;
        }

        foreach (array_keys($context) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $context */
        return $context;
    }

    /** @param array<string, mixed> $context */
    private function buildMessage(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $message . "\n\nContext:\n" . $encodedContext;
    }
}
