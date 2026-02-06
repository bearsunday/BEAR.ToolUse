<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\ResourceObject;

/**
 * Resource that returns error HTTP status codes without throwing exceptions
 */
class StatusError extends ResourceObject
{
    public function onGet(int $code = 400): static
    {
        $this->code = $code;
        $this->body = ['error' => 'Validation failed', 'details' => 'The "name" field is required'];

        return $this;
    }
}
