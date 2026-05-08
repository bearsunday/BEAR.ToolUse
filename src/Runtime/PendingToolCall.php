<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

/**
 * Tool call accumulated during streaming, before its JSON input is decoded
 */
final readonly class PendingToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $inputJson,
    ) {
    }
}
