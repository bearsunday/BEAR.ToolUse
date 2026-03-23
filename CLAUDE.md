# CLAUDE.md

Project-specific development guidelines for BEAR.ToolUse.

## Project Overview

A library that enables AI agent capabilities for BEAR.Sunday applications. Automatically generates Tool Use definitions from resource classes and manages the agent loop with LLMs.

## Directory Structure

```
src/
├── Attribute/           # PHP Attributes
│   ├── Tool.php         # Tool metadata (name, description, confirm, filter)
│   └── Exclude.php      # Exclude from tool exposure
├── Dispatch/            # Tool execution
│   ├── DispatcherInterface.php
│   ├── Dispatcher.php   # Dispatch to BEAR.Resource
│   ├── ToolRegistryInterface.php
│   ├── ToolRegistry.php # Tool name → resource mapping
│   ├── ToolResultFilterInterface.php # Response filter
│   ├── ToolCall.php     # Call from LLM
│   └── ToolResult.php   # Execution result
├── Schema/              # Schema conversion
│   ├── SchemaConverterInterface.php
│   ├── SchemaConverter.php    # Resource → tool definition
│   ├── ToolCollectorInterface.php
│   ├── ToolCollector.php      # Tool collection & registration
│   ├── Tool.php               # Tool definition class
│   ├── AlpsSemanticDictionary.php      # ALPS dictionary
│   ├── JsonSchemaRepositoryInterface.php
│   ├── JsonSchemaRepository.php        # JSON Schema loader
│   ├── ParameterDescriptionResolverInterface.php
│   └── ParameterDescriptionResolver.php # Description resolver
├── Runtime/             # Agent execution
│   ├── AgentInterface.php
│   ├── Agent.php        # Agent loop
│   ├── AgentFactory.php # Agent builder
│   ├── AgentResponse.php
│   ├── ConfirmationHandlerInterface.php # User confirmation (Agent)
│   ├── ConfirmableToolSupport.php # Shared confirmation trait
│   └── Message.php
├── Llm/                 # LLM related
│   ├── LlmClientInterface.php  # User implementation
│   └── LlmResponse.php
└── Module/
    └── ToolUseModule.php    # Ray.Di module
```

## Commands

```bash
composer setup    # Setup development tools
composer test     # PHPUnit tests
composer cs       # PHPCS (coding standards)
composer cs-fix   # PHPCBF (auto-fix)
composer sa       # Psalm (static analysis)
composer phpmd    # PHPMD (mess detector)
composer tests    # cs + sa + test
```

## Design Principles

### Interface-Driven

- All major classes implement interfaces
- DI binds to interfaces
- `LlmClientInterface` must be implemented by users (library does not provide implementation)

### BEAR.Sunday Integration

- Expose resource classes as tools via full URI (`app://self/user`, `page://self/article`)
- `#[Tool]` attribute for metadata customization (name, description, confirm, filter)
- `#[Tool(confirm: true)]` requires user confirmation before tool execution
- `#[Tool(filter: FilterClass::class)]` filters response body before sending to LLM
- `#[Exclude]` attribute to exclude methods/classes from tool exposure (opt-out)
- Supports BEAR\Resource\Annotation\JsonSchema for parameter validation

### Type Safety

- Use `final readonly class` as default
- Explicit array types in PHPDoc (`list<T>`, `array<K, V>`)
- Static analysis with Psalm level 1

## Coding Standards

- Doctrine Coding Standard compliant
- Use null-safe operator (`?->`)
- Add `#[Override]` attribute to interface implementation methods

## Testing

- 100% code coverage is mandatory
- Fake implementations in `tests/Fake/`
- `FakeLlmClient` simulates LLM responses
- `FakeConfirmationHandler` simulates user confirmation
- Fake resource classes in `tests/Fake/Resource/App/`

## Key Type Definitions

```php
// Tool mapping
@psalm-type ToolMapping = array{resourceUri: string, method: string, filter?: class-string<ToolResultFilterInterface>}

// Message content
@psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}

// Input schema
array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
```

## Error Feedback Loop

The agent loop automatically feeds tool execution errors back to the LLM:

1. **Exception errors**: `Dispatcher` catches all `Throwable` → `ToolResult::error()` with `"{ExceptionClass}: {message}"` format
2. **Status code errors**: `Dispatcher` checks `ResourceObject->code >= 400` → `ToolResult::error()` with `"{code}: {json_body}"` format
3. **Unknown tools**: Tool not found in `ToolRegistry` → `ToolResult::error()` with `"Unknown tool: {name}"` format

Error results are sent back to the LLM as `tool_result` messages with `is_error: true`, allowing the LLM to retry or respond appropriately. The error status threshold (400) is defined as a private constant in `Dispatcher`.

## Human-in-the-Loop Confirmation

The agent supports user confirmation before executing destructive tool calls.

### How it works (Agent - synchronous)

1. `#[Tool(confirm: true)]` marks tools as confirmable (class or method level)
2. `SchemaConverter::resolveConfirm()` reads confirm flag (explicit method confirm takes priority, unset falls back to class)
3. `Schema\Tool::$confirm` stores the flag (included in `jsonSerialize()` as `confirm: true` only when true)
4. `Agent::isCancelled()` checks if tool requires confirmation and calls `ConfirmationHandlerInterface`
5. On cancellation, `ToolResult::error()` sends `"User cancelled this operation."` back to LLM

### How it works (StreamingAgent - yield-based)

1. `StreamingAgent` builds `confirmableTools` map from tools with `confirm: true`
2. When a confirmable tool is encountered, yields `AgentEvent::confirmationRequired(toolName, toolId, input, message)`
3. Consumer calls `Generator::send(bool)` — `true` to approve, `false` to cancel
4. If `send()` is not called (e.g. `iterator_to_array()`), defaults to denial (safe default)
5. On cancellation, `ToolResult::error()` sends `"User cancelled this operation."` back to LLM

### Key design decisions

- **Agent**: `ConfirmationHandlerInterface` is user-implemented (like `LlmClientInterface`), called synchronously
- **StreamingAgent**: No handler needed — uses yield-based `Generator::send(bool)` for SSE compatibility
- LLM's text response serves as the confirmation message (no templates needed)
- If no handler is bound (Agent), confirmable tools execute normally (no blocking)
- The `confirm` property is serialized to JSON only when `true` (omitted when `false`)
- `ConfirmableToolSupport` trait provides shared `buildConfirmableTools()` and `CANCELLED_MESSAGE`

## Response Filtering

Filter tool response bodies before sending to LLM to reduce token usage.

### How it works

1. `#[Tool(filter: FilterClass::class)]` specifies a filter (class or method level)
2. `SchemaConverter::resolveFilter()` reads filter class (explicit method filter takes priority, unset falls back to class)
3. `Schema\Tool::$filter` stores the class-string (excluded from `jsonSerialize()`)
4. `ToolCollector` passes `$filter` to `ToolRegistry::register()`
5. `Dispatcher` applies filter on success responses only (errors are not filtered)

### Key design decisions

- Filter class implements `ToolResultFilterInterface` with `__invoke(mixed $body): mixed`
- Filters are pure data transformers instantiated via `new` (no DI needed)
- Filter exceptions are caught by the existing `Throwable` catch and fed back to LLM
- The `filter` property is not serialized to JSON (internal use only)
- `ToolMapping`'s `filter` key is optional (omitted when no filter is set)

## Notes

### Parameter Description Resolution Priority

1. JSON Schema (`#[JsonSchema(params: '...')]` from BEAR.Resource) — resolved in `SchemaConverter::mergeJsonSchemaProperties()`
2. PHPDoc `@param` via reflection — resolved in `ParameterDescriptionResolver::resolve()`
3. ALPS dictionary (`koriym/app-state-diagram` Profile API) — resolved in `ParameterDescriptionResolver::resolve()`

JSON Schema descriptions (with constraints like enum, format, min/max) are handled by `SchemaConverter` before calling the resolver. The resolver handles only PHPDoc (method-specific) and ALPS (application-wide fallback).

### JSON Schema Integration

- Uses `BEAR\Resource\Annotation\JsonSchema` attribute
- Reads from `json_validate_dir` (input parameter schemas)
- Extracts: description, enum, format, minimum/maximum, minLength/maxLength, pattern

### ALPS Integration

- Extracts `title`/`doc->value` from ALPS `SemanticDescriptor`
