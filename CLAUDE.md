# CLAUDE.md

Project-specific development guidelines for BEAR.ToolUse.

## Project Overview

A library that enables AI agent capabilities for BEAR.Sunday applications. Automatically generates Tool Use definitions from resource classes and manages the agent loop with LLMs.

## Directory Structure

```
src/
├── Attribute/           # PHP Attributes
│   └── Tool.php         # Tool exposure control
├── Dispatch/            # Tool execution
│   ├── DispatcherInterface.php
│   ├── Dispatcher.php   # Dispatch to BEAR.Resource
│   ├── ToolRegistryInterface.php
│   ├── ToolRegistry.php # Tool name → resource mapping
│   ├── ToolCall.php     # Call from LLM
│   └── ToolResult.php   # Execution result
├── Schema/              # Schema conversion
│   ├── SchemaConverterInterface.php
│   ├── SchemaConverter.php    # Resource → tool definition
│   ├── ToolCollectorInterface.php
│   ├── ToolCollector.php      # Tool collection & registration
│   ├── Tool.php               # Tool definition class
│   └── AlpsSemanticDictionary.php  # ALPS dictionary
├── Runtime/             # Agent execution
│   ├── AgentInterface.php
│   ├── Agent.php        # Agent loop
│   ├── AgentFactory.php # Agent builder
│   ├── AgentResponse.php
│   └── Message.php
├── Llm/                 # LLM related
│   ├── LlmClientInterface.php  # User implementation
│   └── LlmResponse.php
└── Module/
    └── AgentModule.php  # Ray.Di module
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

- Expose resource classes as tools
- Access resources via `app://` scheme
- Control exposure with `#[Tool]` attribute

### Type Safety

- Use `final readonly class` as default
- Explicit array types in PHPDoc (`list<T>`, `array<K, V>`)
- Static analysis with Psalm level 1

## Coding Standards

- Doctrine Coding Standard compliant
- Use null-safe operator (`?->`)
- Add `#[Override]` attribute to interface implementation methods

## Testing

- Fake implementations in `tests/Fake/`
- `FakeLlmClient` simulates LLM responses
- Fake resource classes in `tests/Fake/Resource/App/`

## Key Type Definitions

```php
// Tool mapping
@psalm-type ToolMapping = array{resourceUri: string, method: string}

// Message content
@psalm-type ContentBlock = array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}

// Input schema
array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
```

## Notes

- Uses `koriym/app-state-diagram` Profile API
- Extracts `title`/`doc->value` from ALPS `SemanticDescriptor`
- Retrieves parameter descriptions from PHPDoc `@param` via reflection
