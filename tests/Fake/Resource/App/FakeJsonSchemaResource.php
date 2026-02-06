<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

class FakeJsonSchemaResource extends ResourceObject
{
    #[JsonSchema(params: 'user.json')]
    public function onGet(int $id, string $status = 'active'): static
    {
        return $this;
    }

    #[JsonSchema(params: 'user.json')]
    public function onPost(string $email, int $age, string $username): static
    {
        return $this;
    }
}
