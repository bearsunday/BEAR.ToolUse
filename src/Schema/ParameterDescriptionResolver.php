<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use Override;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use ReflectionMethod;
use ReflectionParameter;

use function is_array;
use function is_string;
use function lcfirst;
use function str_replace;
use function ucwords;

/**
 * Resolves parameter descriptions from JSON Schema, ALPS, or PHPDoc
 */
final readonly class ParameterDescriptionResolver implements ParameterDescriptionResolverInterface
{
    public function __construct(
        private DocBlockFactoryInterface $docBlockFactory,
        private JsonSchemaRepositoryInterface|null $jsonSchemaRepository = null,
        private AlpsSemanticDictionary|null $dictionary = null,
    ) {
    }

    #[Override]
    public function resolve(ReflectionParameter $param, array|null $jsonSchema): string|null
    {
        $paramName = $param->getName();

        // 1. JSON Schema (highest priority)
        $jsonSchemaDescription = $this->getJsonSchemaDescription($paramName, $jsonSchema);
        if ($jsonSchemaDescription !== null) {
            return $jsonSchemaDescription;
        }

        // 2. ALPS Dictionary
        $alpsDescription = $this->getAlpsDictionaryDescription($paramName);
        if ($alpsDescription !== null) {
            return $alpsDescription;
        }

        // 3. PHPDoc (lowest priority)
        return $this->getPhpDocDescription($param, $paramName);
    }

    /** @param array<string, mixed>|null $jsonSchema */
    private function getJsonSchemaDescription(string $paramName, array|null $jsonSchema): string|null
    {
        if ($jsonSchema === null) {
            return null;
        }

        $paramSchema = $jsonSchema[$paramName] ?? null;
        if (! is_array($paramSchema) || ! isset($paramSchema['description']) || ! is_string($paramSchema['description'])) {
            return null;
        }

        return $paramSchema['description'];
    }

    private function getAlpsDictionaryDescription(string $paramName): string|null
    {
        if ($this->dictionary === null) {
            return null;
        }

        $description = $this->dictionary->get($paramName);
        if ($description !== null) {
            return $description;
        }

        // Try camelCase conversion for snake_case parameters
        $camelCase = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $paramName))));

        return $this->dictionary->get($camelCase);
    }

    private function getPhpDocDescription(ReflectionParameter $param, string $paramName): string|null
    {
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
            if ($tag->getVariableName() !== $paramName) {
                continue;
            }

            $description = (string) $tag->getDescription();

            return $description !== '' ? $description : null;
        }

        return null;
    }

    /**
     * Get JSON Schema for a resource method's parameters
     *
     * @param class-string $resourceClass
     *
     * @return array<string, mixed>|null
     */
    public function getJsonSchemaForMethod(string $resourceClass, string $methodName): array|null
    {
        return $this->jsonSchemaRepository?->getParameterSchema($resourceClass, $methodName);
    }
}
