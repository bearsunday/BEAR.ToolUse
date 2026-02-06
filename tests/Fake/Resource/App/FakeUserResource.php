<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeUserResource extends ResourceObject
{
    public function onGet(int $userId): static
    {
        return $this;
    }

    public function onPost(string $userName, string $email): static
    {
        return $this;
    }
}
