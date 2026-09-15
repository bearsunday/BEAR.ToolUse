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

    public function testSafeOnlyKeepsSafeTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('goUserList'),
            $this->tool('doSyncUser'),
            $this->tool('doDeleteUser'),
            $this->tool('doCreateUser'),
            $this->tool('unknownTool'),
        ]);

        $processed = $processor->process($request);

        $this->assertNotSame($request, $processed);
        $this->assertSame(
            ['goUserList'],
            $this->toolNames($processed),
        );
    }

    public function testSafeAndIdempotentKeepsSafeAndIdempotentTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeAndIdempotent($this->dictionary);
        $request = $this->request([
            $this->tool('goUserList'),
            $this->tool('doSyncUser'),
            $this->tool('doDeleteUser'),
            $this->tool('doCreateUser'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['goUserList', 'doSyncUser', 'doDeleteUser'],
            $this->toolNames($processed),
        );
    }

    public function testSafeOnlyCanAllowUnknownTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnlyAllowingUnknownTools($this->dictionary);
        $request = $this->request([
            $this->tool('goUserList'),
            $this->tool('doDeleteUser'),
            $this->tool('doCreateUser'),
            $this->tool('unknownTool'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['goUserList', 'unknownTool'],
            $this->toolNames($processed),
        );
    }

    public function testSafeAndIdempotentCanAllowUnknownTools(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeAndIdempotentAllowingUnknownTools($this->dictionary);
        $request = $this->request([
            $this->tool('goUserList'),
            $this->tool('doSyncUser'),
            $this->tool('doDeleteUser'),
            $this->tool('doCreateUser'),
            $this->tool('unknownTool'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['goUserList', 'doSyncUser', 'doDeleteUser', 'unknownTool'],
            $this->toolNames($processed),
        );
    }

    public function testCustomAllowedTypes(): void
    {
        $processor = new AlpsToolPolicyInputProcessor($this->dictionary, ['unsafe']);
        $request = $this->request([
            $this->tool('goUserList'),
            $this->tool('doDeleteUser'),
            $this->tool('doCreateUser'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['doCreateUser'],
            $this->toolNames($processed),
        );
    }

    public function testResolvesSnakeCaseToolThroughCamelCaseAlpsId(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('go_user_list'),
            $this->tool('do_delete_user'),
        ]);

        $processed = $processor->process($request);

        $this->assertSame(
            ['go_user_list'],
            $this->toolNames($processed),
        );
    }

    public function testReturnsOriginalRequestWhenNoToolIsFiltered(): void
    {
        $processor = AlpsToolPolicyInputProcessor::safeOnly($this->dictionary);
        $request = $this->request([
            $this->tool('goUserList'),
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
