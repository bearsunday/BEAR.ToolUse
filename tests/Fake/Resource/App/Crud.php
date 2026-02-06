<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;
use RuntimeException;

/**
 * CRUD resource for testing all HTTP methods
 */
class Crud extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'GET'];

        return $this;
    }

    public function onPost(string $name): static
    {
        $this->body = ['name' => $name, 'method' => 'POST'];

        return $this;
    }

    public function onPut(int $id, string $name): static
    {
        $this->body = ['id' => $id, 'name' => $name, 'method' => 'PUT'];

        return $this;
    }

    public function onPatch(int $id, string $name): static
    {
        $this->body = ['id' => $id, 'name' => $name, 'method' => 'PATCH'];

        return $this;
    }

    public function onDelete(int $id): static
    {
        $this->body = ['id' => $id, 'method' => 'DELETE'];

        return $this;
    }
}
