<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\ToolUse\Attribute\Tool as ToolAttribute;
use BEAR\Resource\ResourceObject;

#[ToolAttribute(expose: false)]
class FakeHiddenClassResource extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }
}
