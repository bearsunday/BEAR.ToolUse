<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Fake\Resource\App;

use BEAR\Resource\Annotation\JsonSchema;
use BEAR\Resource\ResourceObject;

class FakeNoPropertiesResource extends ResourceObject
{
    #[JsonSchema(params: 'no_properties.json')]
    public function onGet(int $id): static
    {
        return $this;
    }
}
