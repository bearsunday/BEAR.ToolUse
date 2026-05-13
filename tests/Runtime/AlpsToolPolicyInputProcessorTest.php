<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlpsToolPolicyInputProcessor::class)]
#[CoversClass(LlmRequest::class)]
final class AlpsToolPolicyInputProcessorTest extends TestCase
{
    private AlpsSemanticDictionary $dictionary;

    protected function setUp(): void
    {
        $this->dictionary = new AlpsSemanticDictionary(__DIR__ . '/../Fake/alps-profile.json');
    }

    public function testSafeOnlyKeepsSafeAndIdempotentTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('createUser'),
            $this->tool('syncUser'),
            $this->tool('deleteUser'),
            $this->tool('unknownTool'),
        ]);

        $processed = $processor->process($request);

        $this->assertNotSame($request, $processed);
        $this->assertSame(
            ['createUser', 'syncUser'],
            $this->toolNames($processed),
        );
    }

    public function testSafeOnlyCanAllowUnknownTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnlyAllowingUnknownTools($this->dictionary);
        $request = $this->request([
            $this->tool('createUser'),
            $this->tool('deleteUser'),
            $this->tool('unknownTool'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['createUser', 'unknownTool'],
            $this->toolNames($processed),
        );
    }

    public function testCustomAllowedTypes(): void
    {
        $processor = new AlpsToolPolicyInputProcessor($this->dictionary, ['unsafe']);
        $request = $this->request([
            $this->tool('createUser'),
            $this->tool('deleteUser'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['deleteUser'],
            $this->toolNames($processed),
        );
    }

    public function testResolvesSnakeCaseToolThroughCamelCaseAlpsId(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('create_user'),
            $this->tool('delete_user'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['create_user'],
            $this->toolNames($processed),
        );
    }

    public function testReturnsOriginalRequestWhenNoToolIsFiltered(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('createUser'),
            $this->tool('syncUser'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame($request, $processed);
    }

    /** @param list<Tool> $tools */
    private function request(array $tools): LlmRequest
    {
        return new LlmRequest('system', [Message::user('Run tools')], $tools);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, 'Tool ' . $name, [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ]);
    }

    /** @return list<string> */
    private function toolNames(LlmRequest $request): array
    {
        $names = [];
        foreach ($request->tools as $tool) {
            $names[] = $tool->name;
        }

        return $names;
    }
}
