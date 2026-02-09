<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use Override;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use ReflectionMethod;
use ReflectionParameter;

use function lcfirst;
use function str_replace;
use function ucwords;

/**
 * Resolves parameter descriptions from PHPDoc or ALPS
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
    public function resolve(ReflectionParameter $param): string|null
    {
        $paramName = $param->getName();

        // 1. PHPDoc (highest priority - method-specific)
        $phpDocDescription = $this->getPhpDocDescription($param, $paramName);
        if ($phpDocDescription !== null) {
            return $phpDocDescription;
        }

        // 2. ALPS Dictionary (fallback - application-wide)
        return $this->getAlpsDictionaryDescription($paramName);
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
