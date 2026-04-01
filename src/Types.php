<?php

declare(strict_types=1);

namespace BEAR\ToolUse;

use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use BEAR\ToolUse\Llm\StreamEvent;
use Generator;

/**
 * Domain types for BEAR.ToolUse
 *
 * @psalm-type InputSchema = array{type: string, properties: array<string, mixed>, required?: list<string>}
 * @psalm-type ToolMapping = array{resourceUri: string, method: string, filter?: class-string<ToolResultFilterInterface>}
 * @psalm-type PendingToolCall = array{id: string, name: string, inputJson: string}
 * @psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}
 * @psalm-type StreamGenerator = Generator<int, StreamEvent, mixed, void>
 */
final class Types
{
}
