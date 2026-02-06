<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Fake\Resource\App\FakeDocParamResource;
use BEAR\ToolUse\Fake\Resource\App\FakeEmptyDocResource;
use BEAR\ToolUse\Fake\Resource\App\FakeJsonSchemaResource;
use BEAR\ToolUse\Fake\Resource\App\FakeMissingParamDocResource;
use BEAR\ToolUse\Fake\Resource\App\FakeNoDescriptionResource;
use BEAR\ToolUse\Fake\Resource\App\FakeSnakeCaseResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUserResource;
use Koriym\AppStateDiagram\LabelNameTitle;
use Koriym\AppStateDiagram\Profile;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ParameterDescriptionResolver::class)]
final class ParameterDescriptionResolverTest extends TestCase
{
    public function testResolveFromJsonSchema(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $resolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository);

        $reflection = new ReflectionClass(FakeJsonSchemaResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // id

        $jsonSchema = $jsonSchemaRepository->getParameterSchema(FakeJsonSchemaResource::class, 'onGet');
        $description = $resolver->resolve($param, $jsonSchema);

        $this->assertSame('User ID (1-1000)', $description);
    }

    public function testResolveFromAlpsDictionary(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $profile = new Profile($profilePath, new LabelNameTitle());
        $dictionary = new AlpsSemanticDictionary($profile);
        $resolver = new ParameterDescriptionResolver($docBlockFactory, null, $dictionary);

        $reflection = new ReflectionClass(FakeUserResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // userId

        $description = $resolver->resolve($param, null);

        $this->assertSame('User identifier', $description);
    }

    public function testResolveFromPhpDoc(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $reflection = new ReflectionClass(FakeDocParamResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // id

        $description = $resolver->resolve($param, null);

        $this->assertSame('The unique identifier', $description);
    }

    public function testJsonSchemaTakesPriorityOverAlps(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $profile = new Profile($profilePath, new LabelNameTitle());
        $dictionary = new AlpsSemanticDictionary($profile);
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $resolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository, $dictionary);

        $reflection = new ReflectionClass(FakeJsonSchemaResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // id

        $jsonSchema = $jsonSchemaRepository->getParameterSchema(FakeJsonSchemaResource::class, 'onGet');
        $description = $resolver->resolve($param, $jsonSchema);

        // JSON Schema description should be used
        $this->assertSame('User ID (1-1000)', $description);
    }

    public function testSnakeCaseToCamelCaseForAlps(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $profile = new Profile($profilePath, new LabelNameTitle());
        $dictionary = new AlpsSemanticDictionary($profile);
        $resolver = new ParameterDescriptionResolver($docBlockFactory, null, $dictionary);

        $reflection = new ReflectionClass(FakeSnakeCaseResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // user_id

        $description = $resolver->resolve($param, null);

        // user_id should be converted to userId for ALPS lookup
        $this->assertSame('User identifier', $description);
    }

    public function testReturnsNullWhenNoDescriptionFound(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $reflection = new ReflectionClass(FakeUserResource::class);
        $method = $reflection->getMethod('onPost');
        $param = $method->getParameters()[1]; // email - no description

        $description = $resolver->resolve($param, null);

        $this->assertNull($description);
    }

    public function testGetJsonSchemaForMethod(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $resolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository);

        $schema = $resolver->getJsonSchemaForMethod(FakeJsonSchemaResource::class, 'onGet');

        $this->assertNotNull($schema);
        $this->assertArrayHasKey('id', $schema);
    }

    public function testGetJsonSchemaForMethodReturnsNullWithoutRepository(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $schema = $resolver->getJsonSchemaForMethod(FakeJsonSchemaResource::class, 'onGet');

        $this->assertNull($schema);
    }

    public function testReturnsNullForEmptyPhpDocDescription(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $reflection = new ReflectionClass(FakeEmptyDocResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // id with empty description

        $description = $resolver->resolve($param, null);

        $this->assertNull($description);
    }

    public function testResolveSkipsNonMatchingParamTags(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $reflection = new ReflectionClass(FakeDocParamResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[1]; // name (second parameter)

        $description = $resolver->resolve($param, null);

        // Should skip the first @param (id) and find the second one (name)
        $this->assertSame('The display name for this item', $description);
    }

    public function testReturnsNullWhenParamTagNotFound(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $resolver = new ParameterDescriptionResolver($docBlockFactory);

        $reflection = new ReflectionClass(FakeMissingParamDocResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[1]; // name (not documented in PHPDoc)

        $description = $resolver->resolve($param, null);

        $this->assertNull($description);
    }

    public function testReturnsNullWhenJsonSchemaHasNoDescription(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $resolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository);

        $reflection = new ReflectionClass(FakeNoDescriptionResource::class);
        $method = $reflection->getMethod('onGet');
        $param = $method->getParameters()[0]; // id (has schema but no description)

        $jsonSchema = $jsonSchemaRepository->getParameterSchema(FakeNoDescriptionResource::class, 'onGet');
        $description = $resolver->resolve($param, $jsonSchema);

        // Should return null because JSON Schema property has no description
        $this->assertNull($description);
    }
}
