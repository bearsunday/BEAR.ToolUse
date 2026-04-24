<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

    public function testNonSemanticDescriptorIsExcluded(): void
    {
        // createUser is type=safe (transition) — must NOT appear in dictionary
        $this->assertNull($this->dictionary->get('createUser'));
    }

    public function testLocalHrefResolvesToReferencedDescription(): void
    {
        // userIdAlias has href="#userId" → resolves to "User identifier"
        $this->assertSame('User identifier', $this->dictionary->get('userIdAlias'));
    }

    public function testDanglingHrefIsSkipped(): void
    {
        // danglingHref references a non-existent id — left out of dictionary
        $this->assertNull($this->dictionary->get('danglingHref'));
    }

    public function testHrefChainResolvesAcrossMultipleHops(): void
    {
        // chainA -> chainB -> userId
        $this->assertSame('User identifier', $this->dictionary->get('chainA'));
        $this->assertSame('User identifier', $this->dictionary->get('chainB'));
    }

    public function testCrossFileHrefIsIgnored(): void
    {
        // href that does not start with '#' is not resolved (cross-file ref unsupported)
        $this->assertNull($this->dictionary->get('externalRef'));
    }

    public function testLoadXmlProfile(): void
    {
        $dictionary = new AlpsSemanticDictionary(__DIR__ . '/../Fake/alps-profile.xml');

        $this->assertSame('Resource identifier', $dictionary->get('id'));
        $this->assertSame('User identifier', $dictionary->get('userId'));
        $this->assertSame('Name of the user', $dictionary->get('userName'));
        $this->assertNull($dictionary->get('email'));
        $this->assertSame('Nested description', $dictionary->get('nestedField'));
        $this->assertNull($dictionary->get('createUser'));
        $this->assertSame('User identifier', $dictionary->get('userIdAlias'));
        $this->assertNull($dictionary->get('danglingHref'));
        $this->assertSame('User identifier', $dictionary->get('chainA'));
        $this->assertNull($dictionary->get('externalRef'));
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
        $this->expectExceptionMessageMatches('/Failed to parse ALPS XML profile .*: .+/');

        new AlpsSemanticDictionary(__DIR__ . '/../Fake/invalid-alps.xml');
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Failed to parse ALPS JSON profile/');

        new AlpsSemanticDictionary(__DIR__ . '/../Fake/invalid-alps.json');
    }

    public function testMissingJsonFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unable to read ALPS profile/');

        new AlpsSemanticDictionary(__DIR__ . '/../Fake/no-such-file.json');
    }

    public function testMissingXmlFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unable to read ALPS profile/');

        new AlpsSemanticDictionary(__DIR__ . '/../Fake/no-such-file.xml');
    }
}
