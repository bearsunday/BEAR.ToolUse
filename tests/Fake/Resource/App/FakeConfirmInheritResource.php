<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

/**
 * Class-level confirm: true with method-level #[Tool] that doesn't override confirm
 */
#[Tool(confirm: true)]
class FakeConfirmInheritResource extends ResourceObject
{
    #[Tool(name: 'custom_get')]
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'GET'];

        return $this;
    }

    public function onDelete(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'DELETE'];

        return $this;
    }
}
