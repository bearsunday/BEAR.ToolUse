<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Exclude;
use BEAR\ToolUse\Attribute\Tool as ToolAttribute;
use Override;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

use function str_replace;
use function strtolower;
use function trim;

/**
 * Converts resource classes to Tool definitions
 */
final readonly class SchemaConverter implements SchemaConverterInterface
{
    private const HTTP_METHODS = ['onGet', 'onPost', 'onPut', 'onPatch', 'onDelete'];

    public function __construct(
        private DocBlockFactoryInterface $docBlockFactory,
        private ParameterDescriptionResolverInterface|null $descriptionResolver = null,
        private JsonSchemaRepositoryInterface|null $jsonSchemaRepository = null,
    ) {
    }

    /**
     * Convert a resource class to Tool definitions
     *
     * @param class-string<ResourceObject> $resourceClass
     *
     * @return list<Tool>
     */
    #[Override]
    public function convert(string $resourceClass, string $resourcePath): array
    {
        $reflection = new ReflectionClass($resourceClass);

        if ($this->hasExcludeAttribute($reflection)) {
            return [];
        }

        $classAttribute = $this->getToolAttribute($reflection);
        $tools = [];
        foreach (self::HTTP_METHODS as $methodName) {
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            $method = $reflection->getMethod($methodName);

            if ($this->hasMethodExcludeAttribute($method)) {
                continue;
            }

            $methodAttribute = $this->getMethodToolAttribute($method);
            $tool = $this->createTool($resourceClass, $resourcePath, $method, $classAttribute, $methodAttribute);
            $tools[] = $tool;
        }

        return $tools;
    }

    /** @param class-string $resourceClass */
    private function createTool(
        string $resourceClass,
        string $resourcePath,
        ReflectionMethod $method,
        ToolAttribute|null $classAttribute,
        ToolAttribute|null $methodAttribute,
    ): Tool {
        $httpMethod = strtolower(str_replace('on', '', $method->getName()));
        $name = $this->buildToolName($resourcePath, $httpMethod, $methodAttribute);
        $description = $this->buildDescription($method, $classAttribute, $methodAttribute);
        $confirm = $this->resolveConfirm($classAttribute, $methodAttribute);

        // Load JSON Schema for this method if available
        $jsonSchema = $this->jsonSchemaRepository?->getParameterSchema($resourceClass, $method->getName());

        $inputSchema = $this->buildInputSchema($method, $jsonSchema);

        return new Tool($name, $description, $inputSchema, $confirm);
    }

    private function resolveConfirm(ToolAttribute|null $classAttribute, ToolAttribute|null $methodAttribute): bool
    {
        $confirm = $methodAttribute?->confirm;
        $confirm ??= $classAttribute?->confirm;

        return $confirm ?? false;
    }

    private function buildToolName(string $resourcePath, string $httpMethod, ToolAttribute|null $attribute): string
    {
        $customName = $attribute?->name;
        if ($customName !== null) {
            return $customName;
        }

        $pathPart = str_replace(['/', '-'], '_', trim($resourcePath, '/'));

        return $pathPart . '_' . $httpMethod;
    }

    private function buildDescription(
        ReflectionMethod $method,
        ToolAttribute|null $classAttribute,
        ToolAttribute|null $methodAttribute,
    ): string {
        $methodDesc = $methodAttribute?->description;
        if ($methodDesc !== null) {
            return $methodDesc;
        }

        $classDesc = $classAttribute?->description;
        if ($classDesc !== null) {
            return $classDesc;
        }

        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return '';
        }

        $summary = $this->docBlockFactory->create($docComment)->getSummary();

        return $summary !== '' ? $summary : '';
    }

    /**
     * @param array<string, mixed>|null $jsonSchema
     *
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    private function buildInputSchema(ReflectionMethod $method, array|null $jsonSchema): array
    {
        $properties = [];
        $required = [];

        foreach ($method->getParameters() as $param) {
            $paramSchema = $this->buildParameterSchema($param, $jsonSchema);
            $properties[$param->getName()] = $paramSchema;

            if ($param->isDefaultValueAvailable()) {
                continue;
            }

            $required[] = $param->getName();
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @param array<string, mixed>|null $jsonSchema
     *
     * @return array<string, mixed>
     */
    private function buildParameterSchema(ReflectionParameter $param, array|null $jsonSchema): array
    {
        $schema = $this->buildTypeSchema($param->getType());
        $paramName = $param->getName();

        // Merge JSON Schema properties if available
        $schema = $this->mergeJsonSchemaProperties($schema, $paramName, $jsonSchema);

        // Add description if not already set by JSON Schema
        if (! isset($schema['description']) && $this->descriptionResolver !== null) {
            $description = $this->descriptionResolver->resolve($param, $jsonSchema);
            if ($description !== null) {
                $schema['description'] = $description;
            }
        }

        return $schema;
    }

    /**
     * Merge additional properties from JSON Schema
     *
     * @param array<string, mixed>      $schema     Current schema
     * @param string                    $paramName  Parameter name
     * @param array<string, mixed>|null $jsonSchema JSON Schema properties
     *
     * @return array<string, mixed>
     */
    private function mergeJsonSchemaProperties(array $schema, string $paramName, array|null $jsonSchema): array
    {
        if ($jsonSchema === null || ! isset($jsonSchema[$paramName])) {
            return $schema;
        }

        /** @var array<string, mixed> $jsonSchemaProps */
        $jsonSchemaProps = $jsonSchema[$paramName];

        $keysToMerge = [
            'description',
            'enum',
            'format',
            'minimum',
            'maximum',
            'exclusiveMinimum',
            'exclusiveMaximum',
            'minLength',
            'maxLength',
            'pattern',
        ];

        foreach ($keysToMerge as $key) {
            if (! isset($jsonSchemaProps[$key])) {
                continue;
            }

            /** @var string|int|float|list<string> $value */
            $value = $jsonSchemaProps[$key];
            $schema[$key] = $value;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function buildTypeSchema(ReflectionType|null $type): array
    {
        if ($type instanceof ReflectionUnionType) {
            return $this->buildUnionTypeSchema($type);
        }

        if (! $type instanceof ReflectionNamedType) {
            return ['type' => 'string'];
        }

        $schema = ['type' => $this->mapPhpTypeToJsonType($type->getName())];
        if ($type->allowsNull() && $type->getName() !== 'null') {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function buildUnionTypeSchema(ReflectionUnionType $unionType): array
    {
        $anyOf = [];
        $hasNull = false;

        foreach ($unionType->getTypes() as $type) {
            if (! $type instanceof ReflectionNamedType) {
                continue; // @codeCoverageIgnore
            }

            $typeName = $type->getName();
            if ($typeName === 'null') {
                $hasNull = true;

                continue;
            }

            $anyOf[] = ['type' => $this->mapPhpTypeToJsonType($typeName)];
        }

        if ($hasNull) {
            $anyOf[] = ['type' => 'null'];
        }

        return ['anyOf' => $anyOf];
    }

    private function mapPhpTypeToJsonType(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /** @param ReflectionClass<ResourceObject> $reflection */
    private function getToolAttribute(ReflectionClass $reflection): ToolAttribute|null
    {
        $attributes = $reflection->getAttributes(ToolAttribute::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    private function getMethodToolAttribute(ReflectionMethod $method): ToolAttribute|null
    {
        $attributes = $method->getAttributes(ToolAttribute::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /** @param ReflectionClass<ResourceObject> $reflection */
    private function hasExcludeAttribute(ReflectionClass $reflection): bool
    {
        return $reflection->getAttributes(Exclude::class) !== [];
    }

    private function hasMethodExcludeAttribute(ReflectionMethod $method): bool
    {
        return $method->getAttributes(Exclude::class) !== [];
    }
}
