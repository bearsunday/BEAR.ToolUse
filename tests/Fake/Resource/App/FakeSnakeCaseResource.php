<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * Resource with snake_case parameter names for ALPS dictionary camelCase conversion test
 */
class FakeSnakeCaseResource extends ResourceObject
{
    public function onGet(int $user_id): static
    {
        return $this;
    }
}
