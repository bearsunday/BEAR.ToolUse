# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

### Added
- `ToolCallObserverInterface` invoked once per `Dispatcher` dispatch (success, status>=400, exception, unknown tool) with `ToolCall`, `ToolResult` (post-filter), and elapsed `durationMs`. `ToolUseModule` binds `NullToolCallObserver` (no-op) by default; applications can override the binding to plug in audit logging, metrics, or latency tracking.
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
- Client tools for frontend tool calling: `Tool(client: true)` definitions registered via `AgentFactory::addClientTools()` are exposed to the LLM but never dispatched server-side. A client tool call ends the run — `AgentResponse::STOP_CLIENT_TOOL_USE` (sync) / `AgentEvent::CLIENT_TOOL_CALL` (streaming) — and the consumer executes it (e.g. browser UI update), then continues with `Agent::resume()` / `StreamingAgent::resumeStream()`; server-side results held from the interrupted turn are merged in automatically
- Resume validation: `resume()` / `resumeStream()` throw `InvalidResumeException` unless the supplied result IDs match the awaited client tool calls exactly once each (no missing, extra, or duplicate IDs; no resumption after `reset()`). Server tool results are never accepted from resume input — they must already be held from the interrupted turn. Expected IDs are derived from the conversation itself, so stateless resumption across HTTP requests on a reconstructed agent keeps working; a mixed turn is replayed with the client `tool_use` blocks only
- Malformed client tool arguments from the LLM throw `JsonException` instead of silently degrading to an empty input
- `AgentOptions` for per-run configuration: `withTools()` restricts the tools sent to the LLM for a single `run()` / `runStream()` (unknown names fail before the LLM call, and a call to a tool that is not enabled becomes an error result instead of a dispatch). `resume()` / `resumeStream()` accept the same options
- `InputProcessorInterface` / `OutputProcessorInterface` hooks around each LLM call, carried by `AgentOptions`: input processors rewrite the `LlmRequest` (memory, policy, context injection), output processors inspect the `LlmResponse` or `StreamEvent`. `OutputProcessorGuard` validates the processed output against the original: the stop reason and the tool calls (id, name, input) of every response must be unchanged, and a `tool_use` response must keep the matching `tool_use` content blocks. A processor can neither drop a tool call nor introduce one — turning a text answer into a tool call is rejected
- Agent-as-Tool: `AgentProfile` defines a named subagent, `AgentPool` registers them and creates an isolated `ProfiledAgent` per call, `AgentFactory::addSubagents()` exposes them as `ask_*` tools, and `AgentDelegator` routes `ask_*` calls to the subagent while other tools fall through to the wrapped dispatcher. Per-call `AgentOptions` are merged over the profile's: tool restrictions intersect, so a caller can narrow what the profile allows but never widen it
- `AlpsContextInputProcessor` and `AlpsToolPolicyInputProcessor` for ALPS-aware runtime behaviour: relevant descriptors are injected into the request, and tools are filtered by ALPS transition type (`safe` only, or `safe` and `idempotent`)
- `DenyConfirmationHandler` as a safe default for subagents: confirmable tools are denied rather than executed unattended
- Assistant responses are kept in the conversation history for both `Agent` and `StreamingAgent`, including partial responses at `max_tokens` / `stop_sequence`, so a follow-up `run()` continues with full context
- Client tool registration guards: `ConfirmableClientToolException` rejects `confirm: true` on client tools (confirmation is the client's responsibility), `DuplicateToolNameException` rejects name collisions between client and server tools in both registration orders

### Changed
- `AgentFactory::addTools()` / `addResources()` / `addSubagents()` / `addClientTools()` now throw `DuplicateToolNameException` on a duplicate tool name instead of registering two definitions of the same name, and validate the whole batch before adding anything, so a rejected batch leaves the factory unchanged. Duplicates sent the LLM the same name twice while `ToolRegistry` resolved it to whichever was registered last
- `ToolRegistry::register()` now throws `DuplicateToolMappingException` when a tool name is already mapped to a different resource, method, or filter; re-registering an identical mapping stays a no-op (subagent profiles sharing a resource). Silently overwriting left the tool definition the LLM sees pointing at a different resource than the one dispatched — two URIs whose tool names collide (`app://self/article` and `page://self/article` both yield `article_get`) now fail before the registry is written. Rename one of them with `#[Tool(name: ...)]`
- Input processors may narrow the tool set of a request but no longer widen it: `AgentOptions::processRequest()` drops tools the resolved set did not contain, so a processor cannot re-enable a tool excluded by per-call options or by a subagent profile's ceiling
- `OutputProcessorGuard` now requires exactly one `tool_use` content block per original tool call, paired by position. An added or duplicated block passed the previous check and reached the next request as an assistant tool call with no `tool_result` answering it
- `StreamingAgent` decodes every client tool call's arguments before dispatching any server tool of the same turn. Malformed client JSON aborted the turn after non-idempotent server tools had already run
- `Agent::run()` now ends a `tool_use` response that carries no tool calls as a completed turn instead of appending an empty tool results message and looping to `maxIterations`. `StreamingAgent` already behaved this way
- Whether a tool is client-executed is decided from the registered tools, not from the per-call request, so `run()` and `resume()` classify the conversation the same way. A registered client tool that per-call options disabled is answered with a "not enabled" error result instead of being handed to the consumer, and a client tool an input processor added on the fly stays server-side
- `AlpsContextInputProcessor` merges its context into the trailing user message instead of appending a message of its own: inside a tool loop that message carries the `tool_result` blocks, and a separate message after it would break the tool-result turn
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
