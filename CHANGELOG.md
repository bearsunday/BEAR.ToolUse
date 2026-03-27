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
- ALPS semantic dictionary for parameter descriptions
- Parameter description resolution (JSON Schema > PHPDoc > ALPS)
- Synchronous `Agent` with `ConfirmationHandlerInterface`
- `StreamingAgent` for SSE real-time output with yield-based confirmation
- `ToolList` class for tool collection queries (`isConfirmable()`)
- `ToolResult::cancelled()` for encapsulating cancellation results
- Error feedback loop: exceptions and 4xx/5xx status codes fed back to LLM
- `ToolUseModule` for Ray.Di binding configuration
