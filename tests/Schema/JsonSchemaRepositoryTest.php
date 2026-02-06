<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Fake\Resource\App\FakeJsonSchemaResource;
use BEAR\ToolUse\Fake\Resource\App\FakeNoPropertiesResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUserResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonSchemaRepository::class)]
final class JsonSchemaRepositoryTest extends TestCase
{
    private JsonSchemaRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
    }

    public function testGetParameterSchema(): void
    {
        $schema = $this->repository->getParameterSchema(FakeJsonSchemaResource::class, 'onGet');

        $this->assertNotNull($schema);
        $this->assertArrayHasKey('id', $schema);
        $this->assertSame('User ID (1-1000)', $schema['id']['description']);
        $this->assertSame(1, $schema['id']['minimum']);
        $this->assertSame(1000, $schema['id']['maximum']);
    }

    public function testGetParameterSchemaWithEnum(): void
    {
        $schema = $this->repository->getParameterSchema(FakeJsonSchemaResource::class, 'onGet');

        $this->assertNotNull($schema);
        $this->assertArrayHasKey('status', $schema);
        $this->assertSame(['active', 'inactive', 'pending'], $schema['status']['enum']);
    }

    public function testGetParameterSchemaWithFormat(): void
    {
        $schema = $this->repository->getParameterSchema(FakeJsonSchemaResource::class, 'onPost');

        $this->assertNotNull($schema);
        $this->assertArrayHasKey('email', $schema);
        $this->assertSame('email', $schema['email']['format']);
    }

    public function testGetParameterSchemaWithStringConstraints(): void
    {
        $schema = $this->repository->getParameterSchema(FakeJsonSchemaResource::class, 'onPost');

        $this->assertNotNull($schema);
        $this->assertArrayHasKey('username', $schema);
        $this->assertSame(3, $schema['username']['minLength']);
        $this->assertSame(20, $schema['username']['maxLength']);
        $this->assertSame('^[a-zA-Z0-9_]+$', $schema['username']['pattern']);
    }

    public function testReturnsNullForNonExistentMethod(): void
    {
        $schema = $this->repository->getParameterSchema(FakeJsonSchemaResource::class, 'onPatch');

        $this->assertNull($schema);
    }

    public function testReturnsNullForResourceWithoutJsonSchemaAttribute(): void
    {
        $schema = $this->repository->getParameterSchema(FakeUserResource::class, 'onGet');

        $this->assertNull($schema);
    }

    public function testReturnsNullForNonExistentSchemaFile(): void
    {
        $repository = new JsonSchemaRepository(__DIR__ . '/non-existent-dir');
        $schema = $repository->getParameterSchema(FakeJsonSchemaResource::class, 'onGet');

        $this->assertNull($schema);
    }

    public function testReturnsNullForSchemaWithoutProperties(): void
    {
        $schema = $this->repository->getParameterSchema(FakeNoPropertiesResource::class, 'onGet');

        $this->assertNull($schema);
    }
}
