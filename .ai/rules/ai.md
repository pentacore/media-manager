---
paths:
  - 'app/Ai/**'
---

# Ai

## AI agents and tools
Put agents in `app/Ai/Agents` named `*Agent` with `#[MaxSteps]`, settings-resolved models (never hardcoded) and `UsesFailoverChain`, implementing `modelSelection()` from the matching `AiSettings`/`DecisionAgentSettings` selection; put tools in `app/Ai/Tools/&lt;Integration&gt;` named `*Tool` extending `BaseTool` (the DecisionAgent's tools extend `DecisionTool`, see below), implementing only `description()`, `risk()`, `execute()`, and `schema()`. Never call an upstream API from a Destructive tool — return `['type','target_service','payload']` and let `BaseTool` queue an `ActionRequest`. Per-run state lives in a container-bound `*RunContext`, since the SDK resolves tools fresh from the container.

## Sub-agents
Sub-agents are `*Agent` classes in `app/Ai/Agents` implementing `CanActAsTool` (stable `name()`), `HasStructuredOutput`, `#[MaxSteps]` and `#[RepairToolCalls]`, modelled via `AiSettings::subAgentSelection()`. They are read-only; the parent keeps destructive tools. Their usage rows carry `parent_invocation_id`.

## Tool argument validation
Tools validate argument shape with `$request->validate([...])` inside `execute()`; `BaseTool` turns a `ValidationException` into `{error: invalid_arguments, errors}`. Keep `InvalidArgumentException` for domain/state checks.

## Per-step middleware and provider-gated tools
Agent middleware (1.0) wraps each step: `handle(PendingStep, Closure): mixed` in `app/Ai/Middleware`; tool-using agents use `AnswerOnFinalStep` + `EnforceBudgetEachStep`; chat-facing ones (MediaAgent, structured sub-agents) add `StopWhenClientDisconnected`; `PriceFetcherAgent` (queued verifier runs only) adds `StopWhenPriceRefreshOutOfTime`, which stops the run once `PriceRefreshTimeBox` no longer fits another step. Register `ToolSearch` or provider tools (`CodeExecution`) only when `ProviderCapabilities::everyProviderSupports()` is true for the whole failover chain — the SDK throws a non-failoverable LogicException otherwise.

A step-gating middleware must never exempt `PendingStep::isFirstStep()` to skip its own check "because the caller already checked once before prompting": a provider failover (`UsesFailoverChain`) re-runs the whole agent loop on the fallback provider starting at step 0 again, so an `isFirstStep()` exemption lets that restarted step run unchecked. Check every step, including step 0; a redundant check on the very first provider's own step 0 is harmless.

## Structured sub-agents must answer stream() with a prompt
laravel/ai 1.0 throws "Streaming structured output is not currently supported" for any HasStructuredOutput agent, and a streamed parent run delegates to sub-agents through AgentTool::stream(); AgentTool swallows the throw into an 'Agent failed: …' tool result. Every HasStructuredOutput sub-agent uses `App\Ai\Concerns\ActsAsStructuredSubAgent` (`use ActsAsStructuredSubAgent, Promptable { ActsAsStructuredSubAgent::stream insteadof Promptable; }`), whose `stream()` runs `prompt()` and yields the JSON as one TextDelta and whose `middleware()` is the shared step middleware. Test sub-agents through the parent's stream(), not only prompt().

## Agents own their failover chain
Every agent uses `App\Ai\Concerns\UsesFailoverChain` and implements `modelSelection()`: `provider()` returns `AiSettings::providerChainFor($this->modelSelection())` — always an explicit `[provider => model]` map plus the failover — which laravel/ai reads whenever `prompt()`/`stream()` get no provider. Never pass `provider:` at a call site — sub-agents run through `AgentTool` with no provider and only fail over through the concern. AI-layer traits live in `app/Ai/Concerns` (like the SDK's `Laravel\Ai\Concerns`), not `app/Concerns`.

## Decision tools extend DecisionTool, never BaseTool
The DecisionAgent's tools live in `app/Ai/Tools/Decision` and extend `DecisionTool`: no advisory-mode gate and no auth-bound queueing — they dispatch through `ActionOrchestrator::dispatchFromAgent()` with the run's `DecisionRunContext` (which stays in `app/Ai/Decision`). The base owns context resolution, the capability refusal hook, the per-run cap, JSON encoding and `invalid_arguments` from `$request->validate()`. Keep their domain rejections (`subject_mismatch`, `subject_not_verifiable`, `capability_disabled`, `type_not_allowed`, `replacement_in_flight`, `missing_target`, `dispatch_failed`, `no_action_type_config`, `lookup_failed`, `nothing_importable`, plus the base's `no_active_run` and `max_actions_reached`) byte-for-byte: the DecisionAgent prompt and the security hardening tests depend on them.

## The Media Advisor decides in code
`RunSubtitleAdvisor` decides subtitle replacements with `SubtitleAdvisorDecider`: it queues the projection's unique `automatic_candidate` through `QueueAutomaticReplacementTool` (its re-validation and BaseTool's gated queueing stay the only queueing path), otherwise it routes the case to review with a templated summary. No model runs there (only the optional triage classifier), so no usage row is written. Do not wrap a fixed rule in an agent again.
