<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Attribute;

use Attribute;

/**
 * Controls tool exposure for AI agents
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @codeCoverageIgnore
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Tool
{
    public function __construct(
        public bool $expose = true,
        public string|null $description = null,
        public string|null $name = null,
    ) {
    }
}
