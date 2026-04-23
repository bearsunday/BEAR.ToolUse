<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function libxml_use_internal_errors;

#[CoversClass(AlpsSemanticDictionary::class)]
final class AlpsSemanticDictionaryTest extends TestCase
{
    private AlpsSemanticDictionary $dictionary;

    protected function setUp(): void
    {
        $this->dictionary = new AlpsSemanticDictionary(__DIR__ . '/../Fake/alps-profile.json');
    }

    public function testGetWithTitle(): void
    {
        // userId has title
        $this->assertSame('User identifier', $this->dictionary->get('userId'));
    }

    public function testGetWithDocValue(): void
    {
        // userName has doc.value
        $this->assertSame('Name of the user', $this->dictionary->get('userName'));
    }

    public function testGetWithNoDescription(): void
    {
        // email has neither title nor doc.value
        $this->assertNull($this->dictionary->get('email'));
    }

    public function testGetNonExistentKey(): void
    {
        $this->assertNull($this->dictionary->get('nonexistent'));
    }

    public function testArrayAccess(): void
    {
        $this->assertSame('User identifier', $this->dictionary['userId']);
        $this->assertNull($this->dictionary['nonexistent'] ?? null);
    }

    public function testNestedDescriptorFromJson(): void
    {
        // nestedField is under user.descriptor with a string doc
        $this->assertSame('Nested description', $this->dictionary->get('nestedField'));
    }

    public function testLoadXmlProfile(): void
    {
        $dictionary = new AlpsSemanticDictionary(__DIR__ . '/../Fake/alps-profile.xml');

        $this->assertSame('Resource identifier', $dictionary->get('id'));
        $this->assertSame('User identifier', $dictionary->get('userId'));
        $this->assertSame('Name of the user', $dictionary->get('userName'));
        $this->assertNull($dictionary->get('email'));
        $this->assertSame('Nested description', $dictionary->get('nestedField'));
    }

    public function testUnsupportedExtensionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported ALPS profile format: .yaml');

        new AlpsSemanticDictionary(__DIR__ . '/../Fake/profile.yaml');
    }

    public function testInvalidXmlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Failed to parse ALPS XML profile/');

        $previous = libxml_use_internal_errors(true);
        try {
            new AlpsSemanticDictionary(__DIR__ . '/../Fake/invalid-alps.xml');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
