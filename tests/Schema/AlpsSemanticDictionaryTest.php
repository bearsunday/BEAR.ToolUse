<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Schema;

use Koriym\AppStateDiagram\LabelNameTitle;
use Koriym\AppStateDiagram\Profile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlpsSemanticDictionary::class)]
final class AlpsSemanticDictionaryTest extends TestCase
{
    private AlpsSemanticDictionary $dictionary;

    protected function setUp(): void
    {
        $profilePath = __DIR__ . '/../Fake/alps-profile.json';
        $profile = new Profile($profilePath, new LabelNameTitle());
        $this->dictionary = new AlpsSemanticDictionary($profile);
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
}
