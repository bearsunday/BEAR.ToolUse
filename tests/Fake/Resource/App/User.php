<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class User extends ResourceObject
{
    public function onGet(int $userId): static
    {
        $this->body = ['id' => $userId, 'name' => 'Test User'];

        return $this;
    }

    public function onPost(string $userName, string $email): static
    {
        $this->body = ['name' => $userName, 'email' => $email];

        return $this;
    }
}
