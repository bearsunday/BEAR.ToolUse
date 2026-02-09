<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

class FakeMethodConfirmResource extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'GET'];

        return $this;
    }

    #[Tool(confirm: true)]
    public function onDelete(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'DELETE'];

        return $this;
    }
}
