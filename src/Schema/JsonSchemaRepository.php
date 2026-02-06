<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\Annotation\JsonSchema;
use Override;
use ReflectionClass;
use ReflectionMethod;

use function file_exists;
use function file_get_contents;
use function is_array;
use function json_decode;
use function rtrim;

use const JSON_THROW_ON_ERROR;

/**
 * Repository for JSON Schema definitions from files
 *
 * Uses BEAR\Resource\Annotation\JsonSchema attribute and json_validate_dir binding
 * provided by BEAR\Resource\Module\JsonSchemaModule.
 */
final readonly class JsonSchemaRepository implements JsonSchemaRepositoryInterface
{
    public function __construct(
        private string $validateDir,
    ) {
    }

    /**
     * @param class-string $resourceClass
     *
     * @return array<string, mixed>|null
     */
    #[Override]
    public function getParameterSchema(string $resourceClass, string $methodName): array|null
    {
        $reflection = new ReflectionClass($resourceClass);
        if (! $reflection->hasMethod($methodName)) {
            return null;
        }

        $method = $reflection->getMethod($methodName);
        $schemaFile = $this->getSchemaFile($method);
        if ($schemaFile === null) {
            return null;
        }

        $schemaPath = rtrim($this->validateDir, '/') . '/' . $schemaFile;
        if (! file_exists($schemaPath)) {
            return null;
        }

        $content = file_get_contents($schemaPath);
        if ($content === false) {
            return null; // @codeCoverageIgnore
        }

        /** @var array<string, mixed> $schema */
        $schema = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
            return null;
        }

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];

        return $properties;
    }

    private function getSchemaFile(ReflectionMethod $method): string|null
    {
        $attributes = $method->getAttributes(JsonSchema::class);
        if ($attributes === []) {
            return null;
        }

        $jsonSchema = $attributes[0]->newInstance();

        return $jsonSchema->params;
    }
}
