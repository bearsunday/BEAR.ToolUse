<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * Resource that throws exceptions for testing error handling
 */
class Error extends ResourceObject
{
    public function onGet(): static
    {
        throw new RuntimeException('Test error');
    }
}
