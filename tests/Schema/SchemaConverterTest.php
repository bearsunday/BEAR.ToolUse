<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use BEAR\ToolUse\Fake\Resource\App\FakeArticleResource;
use BEAR\ToolUse\Fake\Resource\App\FakeCustomNameResource;
use BEAR\ToolUse\Fake\Resource\App\FakeDescriptionResource;
use BEAR\ToolUse\Fake\Resource\App\FakeDocParamResource;
use BEAR\ToolUse\Fake\Resource\App\FakeHiddenClassResource;
use BEAR\ToolUse\Fake\Resource\App\FakeHiddenResource;
use BEAR\ToolUse\Fake\Resource\App\FakeJsonSchemaResource;
use BEAR\ToolUse\Fake\Resource\App\FakeSearchResource;
use BEAR\ToolUse\Fake\Resource\App\FakeSnakeCaseResource;
use BEAR\ToolUse\Fake\Resource\App\FakeTypesResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUnionTypeResource;
use BEAR\ToolUse\Fake\Resource\App\FakeUserResource;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_search;

#[CoversClass(SchemaConverter::class)]
final class SchemaConverterTest extends TestCase
{
    private SchemaConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new SchemaConverter(DocBlockFactory::createInstance());
    }

    public function testConvertBasicResource(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/article');

        $this->assertCount(1, $tools);
        $this->assertSame('article_get', $tools[0]->name);
        $this->assertSame('Get an article by ID', $tools[0]->description);
    }

    public function testConvertWithMultipleMethods(): void
    {
        $tools = $this->converter->convert(FakeUserResource::class, '/user');

        $this->assertCount(2, $tools);
        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);
        $this->assertContains('user_get', $toolNames);
        $this->assertContains('user_post', $toolNames);
    }

    public function testExcludeHiddenMethod(): void
    {
        $tools = $this->converter->convert(FakeHiddenResource::class, '/hidden');

        $this->assertCount(1, $tools);
        $this->assertSame('hidden_get', $tools[0]->name);
    }

    public function testExcludeHiddenClass(): void
    {
        $tools = $this->converter->convert(FakeHiddenClassResource::class, '/hidden-class');

        $this->assertCount(0, $tools);
    }

    public function testCustomToolName(): void
    {
        $tools = $this->converter->convert(FakeCustomNameResource::class, '/custom');

        $this->assertCount(1, $tools);
        $this->assertSame('my_custom_tool', $tools[0]->name);
    }

    public function testInputSchema(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/article');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertContains('id', $schema['required']);
    }

    public function testOptionalParameter(): void
    {
        $tools = $this->converter->convert(FakeSearchResource::class, '/search');

        $schema = $tools[0]->inputSchema;
        $this->assertContains('query', $schema['required']);
        $this->assertNotContains('limit', $schema['required']);
    }

    public function testUnionType(): void
    {
        $tools = $this->converter->convert(FakeUnionTypeResource::class, '/union');

        $schema = $tools[0]->inputSchema;
        $this->assertArrayHasKey('id', $schema['properties']);

        // int|string should produce anyOf
        $idSchema = $schema['properties']['id'];
        $this->assertArrayHasKey('anyOf', $idSchema);
        $this->assertCount(2, $idSchema['anyOf']);
    }

    public function testNullableType(): void
    {
        $tools = $this->converter->convert(FakeUnionTypeResource::class, '/union');

        $schema = $tools[0]->inputSchema;
        $formatSchema = $schema['properties']['format'];

        // ?string should have type: string and nullable: true
        $this->assertSame('string', $formatSchema['type']);
        $this->assertTrue($formatSchema['nullable']);
    }

    public function testUnionTypeWithExplicitNull(): void
    {
        $tools = $this->converter->convert(FakeUnionTypeResource::class, '/union');

        // POST method: onPost(string|null $name, int|string|null $mixedId)
        $schema = $tools[1]->inputSchema;

        // string|null should have type: string and nullable: true
        $nameSchema = $schema['properties']['name'];
        $this->assertSame('string', $nameSchema['type']);
        $this->assertTrue($nameSchema['nullable']);

        // int|string|null should produce anyOf with null type
        $mixedSchema = $schema['properties']['mixedId'];
        $this->assertArrayHasKey('anyOf', $mixedSchema);
        $this->assertCount(3, $mixedSchema['anyOf']); // int, string, null
    }

    public function testFloatType(): void
    {
        $tools = $this->converter->convert(FakeTypesResource::class, '/types');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('number', $schema['properties']['price']['type']);
    }

    public function testBoolType(): void
    {
        $tools = $this->converter->convert(FakeTypesResource::class, '/types');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('boolean', $schema['properties']['active']['type']);
    }

    public function testArrayType(): void
    {
        $tools = $this->converter->convert(FakeTypesResource::class, '/types');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('array', $schema['properties']['items']['type']);
    }

    public function testNoTypeDefaultsToString(): void
    {
        $tools = $this->converter->convert(FakeTypesResource::class, '/types');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('string', $schema['properties']['noType']['type']);
    }

    public function testMethodAttributeDescriptionTakesPrecedence(): void
    {
        $tools = $this->converter->convert(FakeDescriptionResource::class, '/desc');
        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $postIndex = array_search('desc_post', $toolNames, true);
        $this->assertSame('Method attribute description', $tools[$postIndex]->description);
    }

    public function testClassAttributeDescriptionFallback(): void
    {
        $tools = $this->converter->convert(FakeDescriptionResource::class, '/desc');
        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $putIndex = array_search('desc_put', $toolNames, true);
        // onPut has no method attribute, falls back to class attribute
        $this->assertSame('Class level description', $tools[$putIndex]->description);
    }

    public function testDocBlockDescriptionFallback(): void
    {
        $tools = $this->converter->convert(FakeDescriptionResource::class, '/desc');
        $toolNames = array_map(static fn (Tool $t) => $t->name, $tools);

        $getIndex = array_search('desc_get', $toolNames, true);
        // onGet has no method attribute, class has attribute so it uses class description
        $this->assertSame('Class level description', $tools[$getIndex]->description);
    }

    public function testConverterWithAlpsDictionary(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $dictionary = new AlpsSemanticDictionary($profilePath);
        $descriptionResolver = new ParameterDescriptionResolver($docBlockFactory, null, $dictionary);
        $converter = new SchemaConverter($docBlockFactory, $descriptionResolver);

        $tools = $converter->convert(FakeUserResource::class, '/user');

        // user_get: onGet(int $userId)
        $getSchema = $tools[0]->inputSchema;
        $this->assertSame('User identifier', $getSchema['properties']['userId']['description']);

        // user_post: onPost(string $userName, string $email)
        $postSchema = $tools[1]->inputSchema;
        $this->assertSame('Name of the user', $postSchema['properties']['userName']['description']);
        // email has no ALPS description, so no description key
        $this->assertArrayNotHasKey('description', $postSchema['properties']['email']);
    }

    public function testPathWithHyphensConvertedToUnderscores(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/my-article');

        $this->assertSame('my_article_get', $tools[0]->name);
    }

    public function testPathWithSlashesConvertedToUnderscores(): void
    {
        $tools = $this->converter->convert(FakeArticleResource::class, '/api/v1/article');

        $this->assertSame('api_v1_article_get', $tools[0]->name);
    }

    public function testSnakeCaseToCamelCaseForAlpsDictionary(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $dictionary = new AlpsSemanticDictionary($profilePath);
        $descriptionResolver = new ParameterDescriptionResolver($docBlockFactory, null, $dictionary);
        $converter = new SchemaConverter($docBlockFactory, $descriptionResolver);

        // user_id should be converted to userId for ALPS lookup
        $tools = $converter->convert(FakeSnakeCaseResource::class, '/snake');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('User identifier', $schema['properties']['user_id']['description']);
    }

    public function testPhpDocParamDescription(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $descriptionResolver = new ParameterDescriptionResolver($docBlockFactory);
        $converter = new SchemaConverter($docBlockFactory, $descriptionResolver);

        $tools = $converter->convert(FakeDocParamResource::class, '/doc-param');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('The unique identifier', $schema['properties']['id']['description']);
        $this->assertSame('The display name for this item', $schema['properties']['name']['description']);
    }

    public function testConverterWithJsonSchema(): void
    {
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $converter = new SchemaConverter(
            DocBlockFactory::createInstance(),
            null,
            $jsonSchemaRepository,
        );

        $tools = $converter->convert(FakeJsonSchemaResource::class, '/json-schema');

        $schema = $tools[0]->inputSchema;

        // id should have minimum/maximum from JSON Schema
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('User ID (1-1000)', $schema['properties']['id']['description']);
        $this->assertSame(1, $schema['properties']['id']['minimum']);
        $this->assertSame(1000, $schema['properties']['id']['maximum']);

        // status should have enum from JSON Schema
        $this->assertSame(['active', 'inactive', 'pending'], $schema['properties']['status']['enum']);
    }

    public function testConverterWithJsonSchemaFormat(): void
    {
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $converter = new SchemaConverter(
            DocBlockFactory::createInstance(),
            null,
            $jsonSchemaRepository,
        );

        $tools = $converter->convert(FakeJsonSchemaResource::class, '/json-schema');

        // POST method
        $schema = $tools[1]->inputSchema;

        // email should have format from JSON Schema
        $this->assertSame('email', $schema['properties']['email']['format']);

        // username should have minLength/maxLength/pattern from JSON Schema
        $this->assertSame(3, $schema['properties']['username']['minLength']);
        $this->assertSame(20, $schema['properties']['username']['maxLength']);
        $this->assertSame('^[a-zA-Z0-9_]+$', $schema['properties']['username']['pattern']);
    }

    public function testJsonSchemaDescriptionTakesPriorityOverPhpDoc(): void
    {
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $converter = new SchemaConverter(
            DocBlockFactory::createInstance(),
            null,
            $jsonSchemaRepository,
        );

        $tools = $converter->convert(FakeJsonSchemaResource::class, '/json-schema');

        // JSON Schema description should be used
        $schema = $tools[0]->inputSchema;
        $this->assertSame('User ID (1-1000)', $schema['properties']['id']['description']);
    }

    public function testJsonSchemaTakesPriorityOverAlps(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $dictionary = new AlpsSemanticDictionary($profilePath);
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $descriptionResolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository, $dictionary);

        $converter = new SchemaConverter(
            $docBlockFactory,
            $descriptionResolver,
            $jsonSchemaRepository,
        );

        $tools = $converter->convert(FakeJsonSchemaResource::class, '/json-schema');

        // JSON Schema description should be used over ALPS dictionary
        $schema = $tools[0]->inputSchema;
        $this->assertSame('User ID (1-1000)', $schema['properties']['id']['description']);
    }

    public function testFallbackToAlpsWhenNoJsonSchema(): void
    {
        $docBlockFactory = DocBlockFactory::createInstance();
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $dictionary = new AlpsSemanticDictionary($profilePath);
        $jsonSchemaRepository = new JsonSchemaRepository(__DIR__ . '/../Fake/json_schema');
        $descriptionResolver = new ParameterDescriptionResolver($docBlockFactory, $jsonSchemaRepository, $dictionary);

        $converter = new SchemaConverter(
            $docBlockFactory,
            $descriptionResolver,
            $jsonSchemaRepository,
        );

        // FakeUserResource has no #[JsonSchema] attribute, so should use ALPS
        $tools = $converter->convert(FakeUserResource::class, '/user');

        $schema = $tools[0]->inputSchema;
        $this->assertSame('User identifier', $schema['properties']['userId']['description']);
    }
}
