# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

### Added
- Resource classes as AI agent tools via `#[Tool]` attribute
- `#[Exclude]` attribute to exclude methods/classes from tool exposure
- `#[Tool(confirm: true)]` for human-in-the-loop confirmation on destructive operations
- `#[Tool(filter: FilterClass::class)]` for response body filtering before sending to LLM
- JSON Schema integration for parameter validation (`#[JsonSchema]`)
- ALPS semantic dictionary for parameter descriptions (JSON and XML profiles)
- Parameter description resolution (JSON Schema > PHPDoc > ALPS)
- Synchronous `Agent` with `ConfirmationHandlerInterface`
- `StreamingAgent` for SSE real-time output with yield-based confirmation
- `ToolList` class for tool collection queries (`isConfirmable()`)
- `ToolResult::cancelled()` for encapsulating cancellation results
- Error feedback loop: exceptions and 4xx/5xx status codes fed back to LLM
- `ToolUseModule` for Ray.Di binding configuration

### Changed
- **BREAKING (interface implementers only)**: `ToolRegistryInterface::get()` now returns `BEAR\ToolUse\Dispatch\ToolMapping|null` instead of an `array{resourceUri: string, method: string, filter?: class-string}|null` shape. Direct `ToolRegistry` users are unaffected (`register()` signature is unchanged). Internal `PendingToolCall` (used by the streaming pipeline) was likewise promoted from `@psalm-type` to `BEAR\ToolUse\Runtime\PendingToolCall` — `final readonly class`. Both promotions remove static-only Psalm enforcement in favor of runtime type safety; consumer code switches from `$mapping['filter'] ?? null` style to property access.
- **BREAKING**: `AlpsSemanticDictionary` now takes a profile file path (`string`) instead of a `Koriym\AppStateDiagram\Profile` instance.

  ```php
  // Before
  $profile    = new Profile($path, new LabelNameTitle());
  $dictionary = new AlpsSemanticDictionary($profile);

  // After
  $dictionary = new AlpsSemanticDictionary($path);
  ```
- ALPS parsing is now self-contained: JSON/XML format auto-detection, `type === 'semantic'` filter, and same-profile `href="#id"` resolution (including multi-hop chains) are handled internally.

### Removed
- Dependency on `koriym/app-state-diagram` (and its transitive deps `koriym/data-file`, `michelf/php-markdown`, `seld/jsonlint`, `symfony/polyfill-php81`). Approx 12 MB of vendor code eliminated.
