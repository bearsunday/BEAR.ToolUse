<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\ToolUse\Attribute\Tool as ToolAttribute;
use BEAR\Resource\ResourceObject;

class FakeCustomNameResource extends ResourceObject
{
    #[ToolAttribute(name: 'my_custom_tool', description: 'Custom tool description')]
    public function onGet(string $param): static
    {
        return $this;
    }
}
