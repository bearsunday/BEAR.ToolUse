<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Attribute;

use Attribute;

/**
 * Defines tool metadata for AI agents
 *
 * @codeCoverageIgnore
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Tool
{
    public function __construct(
        public string|null $name = null,
        public string|null $description = null,
        public bool $confirm = false,
    ) {
    }
}
