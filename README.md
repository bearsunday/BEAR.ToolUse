# BEAR.ToolUse

A library that enables AI agent capabilities for BEAR.Sunday applications.

Automatically generates Tool Use definitions from resource classes and manages the agent loop with LLMs.

## Features

- Auto-generates JSON Schema-based tool definitions from resource classes
- Enhances parameter descriptions using JSON Schema, ALPS profiles, and PHPDoc
- Controls tool exposure via `#[Tool]` and `#[Exclude]` attributes
- URI-based resource specification (`app://self/user`, `page://self/article`)
- LLM-agnostic design (provides interfaces only)

## Requirements

- PHP 8.2+
- BEAR.Sunday

## Installation

```bash
composer require bear/tool-use
```

## Usage

### 1. Define Resource Classes

```php
<?php

namespace MyApp\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

#[Tool(description: 'Manage user information')]
class User extends ResourceObject
{
    /**
     * Get a user
     *
     * @param int $id User ID
     */
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'name' => 'John'];
        return $this;
    }

    /**
     * Create a user
     *
     * @param string $name User name
     * @param string $email Email address
     */
    public function onPost(string $name, string $email): static
    {
        $this->body = ['id' => 1, 'name' => $name, 'email' => $email];
        return $this;
    }
}
```

### 2. Implement LLM Client

```php
<?php

namespace MyApp\Llm;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

final class MyLlmClient implements LlmClientInterface
{
    /**
     * @param list<Message> $messages
     * @param list<Tool> $tools
     */
    public function chat(string $system, array $messages, array $tools): LlmResponse
    {
        // Call LLM API and return response
    }
}
```

### 3. Configure DI Module

```php
<?php

namespace MyApp\Module;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Module\ToolUseModule;
use MyApp\Llm\MyLlmClient;
use Ray\Di\AbstractModule;

final class AppModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new ToolUseModule());
        $this->bind(LlmClientInterface::class)->to(MyLlmClient::class);
    }
}
```

### 4. Run the Agent

```php
<?php

use BEAR\ToolUse\Runtime\AgentFactory;

// Create agent with factory (URI-based)
$agent = $factory
    ->addResources([
        'app://self/user',
        'app://self/article',
        'page://self/search',
    ])
    ->create('You are a helpful assistant.');

// Run the agent
$response = $agent->run('Please get user information for ID 123');

if ($response->completed) {
    echo $response->getText();
}
```

### 5. Conversation History

The agent maintains conversation history across multiple `run()` calls.

```php
// Continue conversation
$response = $agent->run('What is their email?');

// Access message history
$messages = $agent->messages;

// Save for later (e.g., to database or session)
$savedHistory = $agent->messages;

// Restore conversation and continue
$agent->messages = $savedHistory;
$response = $agent->run('Tell me more about this user');

// Clear history to start fresh
$agent->reset();
```

### 6. Streaming Agent

For real-time output (SSE, WebSocket), use the streaming agent. It yields events as the LLM generates output.

```php
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;

// Bind streaming client in DI module
$this->bind(StreamingLlmClientInterface::class)->to(MyStreamingLlmClient::class);
```

```php
// Create streaming agent
$agent = $factory
    ->addResources(['app://self/user', 'app://self/article'])
    ->createStreaming('You are a helpful assistant.');

// Consume events
$gen = $agent->runStream('Get user 123');
while ($gen->valid()) {
    $event = $gen->current();
    match ($event->type) {
        'text_delta'            => sendSseEvent('text', $event->data['text']),
        'tool_start'            => sendSseEvent('status', "Calling {$event->data['toolName']}..."),
        'tool_result'           => sendSseEvent('status', "{$event->data['toolName']} done"),
        'confirmation_required' => sendSseEvent('confirm', json_encode($event)),
        'completed'             => sendSseEvent('done', $event->data['fullText']),
        'error'                 => sendSseEvent('error', $event->data['message']),
    };
    // For confirmation events, send user's response via Generator::send()
    if ($event->type === 'confirmation_required') {
        $approved = waitForUserConfirmation(); // your app logic
        $gen->send($approved);
    } else {
        $gen->next();
    }
}
```

`AgentEvent` implements `JsonSerializable` for direct use in SSE responses:

```php
echo "data: " . json_encode($event) . "\n\n";
```

## Controlling Tool Exposure

### Exclude Specific Methods

```php
use BEAR\ToolUse\Attribute\Exclude;

class User extends ResourceObject
{
    public function onGet(int $id): static { /* Exposed */ }

    #[Exclude]
    public function onDelete(int $id): static { /* Hidden */ }
}
```

### Exclude Entire Class

```php
use BEAR\ToolUse\Attribute\Exclude;

#[Exclude]
class InternalResource extends ResourceObject
{
    // All methods in this resource are hidden
}
```

### Custom Tool Name and Description

```php
use BEAR\ToolUse\Attribute\Tool;

#[Tool(name: 'search_users', description: 'Search for users')]
public function onGet(string $query): static { /* ... */ }
```

## Human-in-the-Loop Confirmation

Add `confirm: true` to require user confirmation before executing destructive tool calls.

### Mark Tools as Confirmable

```php
use BEAR\ToolUse\Attribute\Tool;

// Class level - all methods require confirmation
#[Tool(confirm: true)]
class User extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }
    public function onDelete(int $id): static { /* ... */ }
}

// Method level - only specific methods require confirmation
class Article extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }

    #[Tool(confirm: true)]
    public function onDelete(int $id): static { /* ... */ }
}
```

### Implement Confirmation Handler

```php
use BEAR\ToolUse\Runtime\ConfirmationHandlerInterface;
use BEAR\ToolUse\Dispatch\ToolCall;

final class CliConfirmationHandler implements ConfirmationHandlerInterface
{
    public function confirm(ToolCall $toolCall, string $llmText): bool
    {
        echo $llmText . "\nProceed? [Y/n]: ";

        $line = fgets(STDIN);

        return $line !== false && trim($line) !== 'n';
    }
}
```

### Bind in DI Module

```php
$this->bind(ConfirmationHandlerInterface::class)->to(CliConfirmationHandler::class);
```

### How It Works

The LLM's text response serves as the confirmation message. No templates needed.

```text
User: "Delete article 123"
  ↓
LLM: "I will delete article 123 'Introduction to BEAR.Sunday'."
     tool_use: article_delete({id: 123})
  ↓
ConfirmationHandler: "I will delete article 123 'Introduction to BEAR.Sunday'."
                     Proceed? [Y/n]:
  ↓
Y → Tool executed
N → "User cancelled this operation." → LLM: "Understood."
```

If no `ConfirmationHandlerInterface` is bound, confirmable tools execute normally (no blocking).

### Streaming Agent Confirmation

`StreamingAgent` uses a yield-based approach instead of `ConfirmationHandlerInterface`. When a confirmable tool is encountered, it yields a `confirmation_required` event and receives the user's response via `Generator::send(bool)`.

```text
StreamingAgent yields: confirmation_required (toolName, input, message)
  ↓
SSE sends confirmation event to client → Client shows UI
  ↓
Client responds via separate HTTP request
  ↓
Server calls: $generator->send(true)  // or false to cancel
  ↓
StreamingAgent resumes: tool executed or cancelled
```

If `send()` is not called (e.g. `iterator_to_array()`), the tool is **denied by default** (safe default).

## Response Filtering

Use `filter` to reduce the response body before sending to the LLM. This improves token efficiency for resources returning large payloads.

### Define a Filter

```php
use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use Override;

final readonly class SummaryFilter implements ToolResultFilterInterface
{
    #[Override]
    public function __invoke(mixed $body): mixed
    {
        // Extract only the fields the LLM needs
        return array_map(fn (array $item) => [
            'id' => $item['id'],
            'title' => $item['title'],
        ], $body);
    }
}
```

### Apply to Resource

```php
use BEAR\ToolUse\Attribute\Tool;

// Class level - all methods use the filter
#[Tool(filter: SummaryFilter::class)]
class Search extends ResourceObject
{
    public function onGet(string $query): static { /* ... */ }
}

// Method level - only specific methods use the filter
class Article extends ResourceObject
{
    #[Tool(filter: SummaryFilter::class)]
    public function onGet(string $query): static { /* ... */ }

    public function onPost(string $title, string $body): static { /* ... */ }
}
```

Filters are only applied to success responses. Error responses (status code >= 400) are sent unfiltered.

## Observing Tool Calls

Hook into every tool call to record audit logs, emit metrics, or measure latency. The observer is invoked once per dispatch — across success, status-code errors, exceptions, and unknown-tool paths — receiving the `ToolCall`, the `ToolResult`, and the elapsed time in milliseconds.

### Implement an Observer

```php
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolCallObserverInterface;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;

final readonly class AuditLogObserver implements ToolCallObserverInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void
    {
        $this->logger->info('tool_call', [
            'name' => $toolCall->name,
            'input' => $toolCall->input,
            'isError' => $result->isError,
            'durationMs' => $durationMs,
        ]);
    }
}
```

> **Note:** `$toolCall->input` and `$result->content` may contain sensitive values (PII, credentials, API tokens) depending on the underlying resource. Sanitize or redact such fields before writing to persistent logs, traces, or external systems.

### Bind in DI Module

```php
$this->bind(ToolCallObserverInterface::class)->to(AuditLogObserver::class);
```

If no observer is bound, `NullToolCallObserver` (a no-op) is used by default.

### Design Notes

- The observer receives the `ToolResult` **after** the response filter has been applied — i.e. exactly what the LLM will see.
- The interface is intentionally minimal. Application-level context (thread/conversation IDs, user ID, etc.) belongs in your own stateful observer implementation, not in the interface signature.
- `Dispatcher` ensures the observer is called exactly once per dispatch, regardless of which branch produced the result.
- **Cancelled tool calls bypass the Dispatcher entirely.** Confirmation denial is handled at the `Agent` / `StreamingAgent` layer (via `ConfirmationHandlerInterface` or `Generator::send(false)`), which returns `ToolResult::cancelled()` without calling `Dispatcher::dispatch()`. The observer is therefore **not** invoked for cancellations.
- **Exceptions thrown from `observe()` propagate out of `Dispatcher::dispatch()`.** Observer implementations are responsible for handling their own errors (e.g. wrapping persistence calls in try/catch) if observation failures should not break tool dispatch.

## JSON Schema Integration

Use BEAR.Resource's JSON Schema for enhanced parameter definitions.

### 1. Install with JsonSchemaModule

```php
use BEAR\Resource\Module\JsonSchemaModule;
use BEAR\ToolUse\Module\ToolUseModule;

$this->install(
    new JsonSchemaModule(
        $this->appMeta->appDir . '/var/json_schema',
        $this->appMeta->appDir . '/var/json_validate',
    ),
);
$this->install(new ToolUseModule());
```

### 2. Define JSON Schema

```json
// /path/to/validate/user.json
{
    "type": "object",
    "properties": {
        "id": {
            "type": "integer",
            "description": "User ID",
            "minimum": 1
        },
        "status": {
            "type": "string",
            "description": "User status",
            "enum": ["active", "inactive", "pending"]
        }
    }
}
```

### 3. Apply to Resource

```php
use BEAR\Resource\Annotation\JsonSchema;

class User extends ResourceObject
{
    #[JsonSchema(params: 'user.json')]
    public function onGet(int $id, string $status = 'active'): static
    {
        // JSON Schema provides both runtime validation and tool definitions
    }
}
```

The following properties are extracted from JSON Schema:
- `description` - Parameter description
- `enum` - Allowed values
- `format` - Value format (email, uri, date, etc.)
- `minimum` / `maximum` - Numeric range
- `minLength` / `maxLength` - String length
- `pattern` - Regex pattern

## ALPS Semantic Descriptions

Use ALPS profiles to enhance parameter descriptions.

```php
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\SchemaConverter;

$dictionary = new AlpsSemanticDictionary('/path/to/profile.json');
$converter = new SchemaConverter($dictionary);
```

Both **JSON** and **XML** ALPS profiles are supported (format is detected from the file extension). The `title` or `doc` of `semantic` descriptors is used as the parameter description. Same-profile `href="#id"` references are resolved automatically; non-semantic descriptors (`safe` / `unsafe` / `idempotent`) are excluded.

## Parameter Description Priority

When multiple sources provide descriptions, they are resolved in this order:

1. **JSON Schema** - `description` property from schema file (+ constraints like `enum`, `format`, `min/max`)
2. **PHPDoc** - `@param` tag description (method-specific)
3. **ALPS** - `title` or `doc.value` from semantic descriptor (application-wide fallback)

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Agent                                │
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐   │
│  │ LlmClient   │───▶│   Message    │───▶│  Dispatcher   │   │
│  │ (Interface) │    │   Loop       │    │               │   │
│  └─────────────┘    └──────────────┘    └───────┬───────┘   │
│                                                 │           │
│  ┌─────────────────────────────────────────────┐│           │
│  │              ToolRegistry                   ││           │
│  │  tool_name → {resourceUri, method}          ││           │
│  └─────────────────────────────────────────────┘│           │
│                                                 ▼           │
│                                         ┌───────────────┐   │
│                                         │ BEAR.Resource │   │
│                                         └───────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Error Feedback Loop

When a tool execution fails, the error is automatically fed back to the LLM, which can then retry with corrected parameters or take alternative action. This works for both exception-based errors and non-2xx status codes.

```
User: "Delete user 999"
  ↓
LLM: tool_use → user_delete(id: 999)
  ↓
Dispatcher: 404 Not Found → ToolResult(isError: true)
  ↓
LLM receives error, decides next action
  ↓
LLM: "User 999 was not found."
```

Errors detected by the Dispatcher:

| Error Type | Example | Error Message Format |
|------------|---------|---------------------|
| Exception | `ResourceNotFoundException` | `BEAR\Resource\Exception\ResourceNotFoundException: /user?id=999` |
| Status code | `$this->code = 400` | `400: {"error":"Validation failed"}` |
| Unknown tool | Tool not registered | `Unknown tool: foo_bar` |

## API

### Interfaces

| Interface | Description |
|-----------|-------------|
| `LlmClientInterface` | LLM API client (user implementation) |
| `StreamingLlmClientInterface` | Streaming LLM API client (user implementation) |
| `DispatcherInterface` | Dispatches tool calls |
| `ToolRegistryInterface` | Maps tool names to resources |
| `SchemaConverterInterface` | Converts resources to tool definitions |
| `ToolCollectorInterface` | Collects and registers tools |
| `AgentInterface` | Agent runtime |
| `StreamingAgentInterface` | Streaming agent runtime |
| `ToolResultFilterInterface` | Response filter before sending to LLM |
| `ConfirmationHandlerInterface` | User confirmation for destructive tools |
| `ToolCallObserverInterface` | Hook invoked once per tool dispatch (audit, metrics, latency) |

### Main Classes

| Class | Description |
|-------|-------------|
| `Agent` | Manages conversation loop with LLM |
| `StreamingAgent` | Streaming conversation loop yielding `AgentEvent` |
| `AgentFactory` | Builder for agents (sync and streaming) |
| `AgentResponse` | Agent execution result (sync) |
| `AgentEvent` | Streaming event (`JsonSerializable`) |
| `StreamEvent` | Low-level LLM stream event |
| `Tool` | Tool definition (JSON Schema) |
| `ToolCall` | Tool call from LLM |
| `ToolResult` | Tool execution result |
| `Message` | Conversation message |
| `LlmResponse` | Response from LLM |

## Development

```bash
# Setup development tools
composer setup

# Run tests
composer test

# Check coding standards
composer cs

# Static analysis
composer sa

# Run all checks
composer tests
```

## Documentation

- [README.ja.md](README.ja.md) - Japanese documentation

## License

MIT License
