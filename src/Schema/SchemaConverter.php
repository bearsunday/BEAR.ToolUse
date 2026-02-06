<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool as ToolAttribute;
use Override;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

use function lcfirst;
use function str_replace;
use function strtolower;
use function trim;
use function ucwords;

/**
 * Converts resource classes to Tool definitions
 */
final readonly class SchemaConverter implements SchemaConverterInterface
{
    private const HTTP_METHODS = ['onGet', 'onPost', 'onPut', 'onPatch', 'onDelete'];

    public function __construct(
        private readonly DocBlockFactoryInterface $docBlockFactory,
        private readonly AlpsSemanticDictionary|null $dictionary = null,
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
        $classAttribute = $this->getToolAttribute($reflection);

        if ($classAttribute !== null && ! $classAttribute->expose) {
            return [];
        }

        $tools = [];
        foreach (self::HTTP_METHODS as $methodName) {
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            $method = $reflection->getMethod($methodName);
            $methodAttribute = $this->getMethodToolAttribute($method);

            if ($methodAttribute !== null && ! $methodAttribute->expose) {
                continue;
            }

            $tool = $this->createTool($resourcePath, $method, $classAttribute, $methodAttribute);
            $tools[] = $tool;
        }

        return $tools;
    }

    private function createTool(
        string $resourcePath,
        ReflectionMethod $method,
        ToolAttribute|null $classAttribute,
        ToolAttribute|null $methodAttribute,
    ): Tool {
        $httpMethod = strtolower(str_replace('on', '', $method->getName()));
        $name = $this->buildToolName($resourcePath, $httpMethod, $methodAttribute);
        $description = $this->buildDescription($method, $classAttribute, $methodAttribute);
        $inputSchema = $this->buildInputSchema($method);

        return new Tool($name, $description, $inputSchema);
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
        if ($docComment !== false) {
            $docBlock = $this->docBlockFactory->create($docComment);
            $summary = $docBlock->getSummary();
            if ($summary !== '') {
                return $summary;
            }
        }

        return '';
    }

    /** @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>} */
    private function buildInputSchema(ReflectionMethod $method): array
    {
        $properties = [];
        $required = [];

        foreach ($method->getParameters() as $param) {
            $paramSchema = $this->buildParameterSchema($param);
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

    /** @return array<string, mixed> */
    private function buildParameterSchema(ReflectionParameter $param): array
    {
        $schema = $this->buildTypeSchema($param->getType());

        $description = $this->getParameterDescription($param);
        if ($description !== null) {
            $schema['description'] = $description;
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

    private function getParameterDescription(ReflectionParameter $param): string|null
    {
        $paramName = $param->getName();

        if ($this->dictionary !== null) {
            $description = $this->dictionary->get($paramName);
            if ($description !== null) {
                return $description;
            }

            $camelCase = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $paramName))));
            $description = $this->dictionary->get($camelCase);
            if ($description !== null) {
                return $description;
            }
        }

        $method = $param->getDeclaringFunction();
        if (! $method instanceof ReflectionMethod) {
            return null; // @codeCoverageIgnore
        }

        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $docBlock = $this->docBlockFactory->create($docComment);
        /** @var list<Param> $paramTags */
        $paramTags = $docBlock->getTagsByName('param');
        foreach ($paramTags as $tag) {
            if ($tag->getVariableName() === $paramName) {
                $description = (string) $tag->getDescription();

                return $description !== '' ? $description : null;
            }
        }

        return null;
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
}
