---
paths:
  - 'app/Ai/**'
---

# Ai

## AI agents and tools
Put agents in `app/Ai/Agents` named `*Agent` with `#[MaxSteps]` and settings-resolved models (never hardcoded); put tools in `app/Ai/Tools/&lt;Integration&gt;` named `*Tool` extending `BaseTool`, implementing only `description()`, `risk()`, `execute()`, and `schema()`. Never call an upstream API from a Destructive tool — return `['type','target_service','payload']` and let `BaseTool` queue an `ActionRequest`. Per-run state lives in a container-bound `*RunContext`, since the SDK resolves tools fresh from the container.

## Sub-agents
Sub-agents are `*Agent` classes in `app/Ai/Agents` implementing `CanActAsTool` (stable `name()`), `HasStructuredOutput`, `#[MaxSteps]` and `#[RepairToolCalls]`, modelled via `AiSettings::subAgentModel()`. They are read-only; the parent keeps destructive tools. Their usage rows carry `parent_invocation_id`.

## Tool argument validation
Tools validate argument shape with `$request->validate([...])` inside `execute()`; `BaseTool` turns a `ValidationException` into `{error: invalid_arguments, errors}`. Keep `InvalidArgumentException` for domain/state checks.

## Per-step middleware and provider-gated tools
Agent middleware (1.0) wraps each step: `handle(PendingStep, Closure): mixed` in `app/Ai/Middleware`; tool-using agents use `AnswerOnFinalStep` + `EnforceBudgetEachStep`. Register `ToolSearch` or provider tools (`CodeExecution`) only when `ProviderCapabilities::everyProviderSupports()` is true for the whole failover chain — the SDK throws a non-failoverable LogicException otherwise.

## Structured sub-agents must answer stream() with a prompt
laravel/ai 1.0 throws "Streaming structured output is not currently supported" for any HasStructuredOutput agent, and a streamed parent run delegates to sub-agents through AgentTool::stream(); AgentTool swallows the throw into an 'Agent failed: …' tool result. Every HasStructuredOutput sub-agent overrides stream() to run prompt() and yield its JSON as one TextDelta (see StuckDownloadInvestigatorAgent). Test sub-agents through the parent's stream(), not only prompt().
