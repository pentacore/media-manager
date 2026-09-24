---
paths:
  - 'app/Listeners/Ai/**'
---

# Listeners Ai

## Guard AI requests via PromptingAgent, and register the streaming subclass explicitly
Pre-flight vetoes for AI calls (rate limits, similar gates) belong in a listener on the laravel/ai `PromptingAgent` event, not copy-pasted into the six agent call sites: it fires inside the SDK failover loop with provider+model resolved, and throwing a `Laravel\Ai\Exceptions\RateLimitedException` (or subclass) there makes the SDK fall over to the failover provider like a real 429. Event discovery binds the exact type-hinted class only, and the real gateway dispatches the `StreamingAgent`/`AgentStreamed` subclasses for streams — so every such listener also needs an explicit `Event::listen(StreamingAgent::class, ...)` in `AIServiceProvider::boot()` (see `EnforceAiRateLimit`, `RecordAgentUsage`). Faked agents (`Agent::fake()`) still dispatch these events, so a guard fires in fake-backed tests too.
