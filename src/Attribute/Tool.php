<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Attribute;

use Attribute;
use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;

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
        public bool|null $confirm = null,
        /** @var class-string<ToolResultFilterInterface>|null */
        public string|null $filter = null,
    ) {
    }
}
