<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool as ToolAttribute;

/**
 * Resource with custom tool name ending in _post
 */
class FakeCustomPostResource extends ResourceObject
{
    #[ToolAttribute(name: 'custom_action_post', description: 'Custom POST action')]
    public function onPost(string $data): static
    {
        return $this;
    }
}
