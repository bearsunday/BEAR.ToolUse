<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

class FakeUnionTypeResource extends ResourceObject
{
    public function onGet(int|string $id, ?string $format = null): static
    {
        return $this;
    }

    /**
     * Test union type with explicit null (string|null)
     */
    public function onPost(string|null $name, int|string|null $mixedId): static
    {
        return $this;
    }
}
