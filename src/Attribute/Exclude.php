<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Attribute;

use Attribute;

/**
 * Excludes a method or class from tool exposure
 *
 * @codeCoverageIgnore
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Exclude
{
}
