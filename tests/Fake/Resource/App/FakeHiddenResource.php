<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\ToolUse\Attribute\Tool as ToolAttribute;
use BEAR\Resource\ResourceObject;

class FakeHiddenResource extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }

    #[ToolAttribute(expose: false)]
    public function onPost(string $data): static
    {
        return $this;
    }
}
