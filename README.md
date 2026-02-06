# BEAR.ToolUse

A library that enables AI agent capabilities for BEAR.Sunday applications.

Automatically generates Tool Use definitions from resource classes and manages the agent loop with LLMs.

## Features

- Auto-generates JSON Schema-based tool definitions from resource classes
- Enhances semantic descriptions using ALPS profiles
- Controls tool exposure via `#[Tool]` attribute
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

use BEAR\ToolUse\Attribute\Tool;
use BEAR\Resource\ResourceObject;

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
use MyApp\Resource\App\User;

// Create agent with factory
$agent = $factory
    ->addResource(User::class, '/user')
    ->create('You are a helpful assistant.');

// Run the agent
$response = $agent->run('Please get user information for ID 123');

if ($response->completed) {
    echo $response->getText();
}
```

## Controlling Tool Exposure

### Hide Specific Methods

```php
use BEAR\ToolUse\Attribute\Tool;

class User extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }

    #[Tool(expose: false)]
    public function onDelete(int $id): static { /* Hidden */ }
}
```

### Hide Entire Class

```php
#[Tool(expose: false)]
class InternalResource extends ResourceObject
{
    // All methods in this resource are hidden
}
```

### Custom Tool Name

```php
#[Tool(name: 'search_users', description: 'Search for users')]
public function onGet(string $query): static { /* ... */ }
```

## ALPS Semantic Descriptions

Use ALPS profiles to enhance parameter descriptions.

```php
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\SchemaConverter;

$dictionary = new AlpsSemanticDictionary('/path/to/profile.json');
$converter = new SchemaConverter($dictionary);
```

The `title` or `doc.value` from ALPS `semantic` descriptors will be used as parameter descriptions.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Agent                                 │
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐  │
│  │ LlmClient   │───▶│   Message    │───▶│  Dispatcher   │  │
│  │ (Interface) │    │   Loop       │    │               │  │
│  └─────────────┘    └──────────────┘    └───────┬───────┘  │
│                                                  │          │
│  ┌─────────────────────────────────────────────┐│          │
│  │              ToolRegistry                    ││          │
│  │  tool_name → {resourceUri, method}          ││          │
│  └─────────────────────────────────────────────┘│          │
│                                                  ▼          │
│                                         ┌───────────────┐  │
│                                         │ BEAR.Resource │  │
│                                         └───────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## API

### Interfaces

| Interface | Description |
|-----------|-------------|
| `LlmClientInterface` | LLM API client (user implementation) |
| `DispatcherInterface` | Dispatches tool calls |
| `ToolRegistryInterface` | Maps tool names to resources |
| `SchemaConverterInterface` | Converts resources to tool definitions |
| `ToolCollectorInterface` | Collects and registers tools |
| `AgentInterface` | Agent runtime |

### Main Classes

| Class | Description |
|-------|-------------|
| `Agent` | Manages conversation loop with LLM |
| `AgentFactory` | Builder for agents |
| `AgentResponse` | Agent execution result |
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
