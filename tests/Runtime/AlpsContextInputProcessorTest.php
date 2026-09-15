<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlpsContextInputProcessor::class)]
#[CoversClass(LlmRequest::class)]
#[CoversClass(Message::class)]
final class AlpsContextInputProcessorTest extends TestCase
{
    private AlpsSemanticDictionary $dictionary;

    protected function setUp(): void
    {
        $this->dictionary = new AlpsSemanticDictionary(__DIR__ . '/../Fake/alps-profile.json');
    }

    public function testAddsRelevantAlpsSemanticsForToolParameters(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Find the user')],
            [
                new Tool('user_get', 'Get user', [
                    'type' => 'object',
                    'properties' => [
                        'userId' => ['type' => 'integer'],
                        'email' => ['type' => 'string'],
                        'missing' => ['type' => 'string'],
                    ],
                    'required' => ['userId'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertNotSame($request, $processed);
        $this->assertSame('system', $processed->systemPrompt);
        $this->assertSame($request->tools, $processed->tools);
        $this->assertCount(1, $processed->messages);
        $this->assertSame(
            "Application semantics from ALPS:\n- user_get.userId: User identifier",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testResolvesSnakeCaseParameterThroughCamelCaseAlpsId(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Create the user')],
            [
                new Tool('user_post', 'Create user', [
                    'type' => 'object',
                    'properties' => [
                        'user_name' => ['type' => 'string'],
                    ],
                    'required' => ['user_name'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "Application semantics from ALPS:\n- user_post.user_name: Name of the user",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testAddsToolNameSemanticsWhenAvailable(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Create the user')],
            [
                new Tool('userId', 'User identifier tool', [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "Application semantics from ALPS:\n- userId: User identifier",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testAddsTransitionTypeForMatchingToolDescriptor(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Create the user')],
            [
                new Tool('goUserList', 'Open user list', [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "Application semantics from ALPS:\n- goUserList [safe]: Open user list transition",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testResolvesSnakeCaseToolThroughCamelCaseAlpsId(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Create the user')],
            [
                new Tool('go_user_list', 'Open user list', [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "Application semantics from ALPS:\n- go_user_list [safe]: Open user list transition",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testReturnsOriginalRequestWhenNoSemanticsMatch(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Call tool')],
            [
                new Tool('unknown_get', 'Unknown', [
                    'type' => 'object',
                    'properties' => [
                        'unknown' => ['type' => 'string'],
                    ],
                    'required' => ['unknown'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame($request, $processed);
    }

    public function testAppendsAMessageWhenTheConversationDoesNotEndWithAUserMessage(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [],
            [
                new Tool('user_get', 'Get user', [
                    'type' => 'object',
                    'properties' => ['userId' => ['type' => 'integer']],
                    'required' => ['userId'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertCount(1, $processed->messages);
        $this->assertSame('user', $processed->messages[0]->role);
        $this->assertSame(
            "Application semantics from ALPS:\n- user_get.userId: User identifier",
            $processed->messages[0]->content[0]['text'],
        );
    }

    public function testCustomHeading(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary, 'ALPS context:');
        $request = new LlmRequest(
            'system',
            [Message::user('Find resource')],
            [
                new Tool('resource_get', 'Get resource', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "ALPS context:\n- resource_get.id: Resource identifier",
            $processed->messages[0]->content[1]['text'],
        );
    }

    public function testDuplicateSemanticLinesAreCollapsed(): void
    {
        $processor = new AlpsContextInputProcessor($this->dictionary);
        $request = new LlmRequest(
            'system',
            [Message::user('Find users')],
            [
                new Tool('user_get', 'Get user', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ]),
                new Tool('user_search', 'Search user', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ]),
                new Tool('user_get', 'Get user again', [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ]),
            ],
        );

        $processed = $processor->process($request);

        $this->assertSame(
            "Application semantics from ALPS:\n"
            . "- user_get.id: Resource identifier\n"
            . '- user_search.id: Resource identifier',
            $processed->messages[0]->content[1]['text'],
        );
    }
}
