---
paths:
  - 'app/Ai/**'
---

# Ai

## AI agents and tools
Put agents in `app/Ai/Agents` named `*Agent` with `#[MaxSteps]` and settings-resolved models (never hardcoded); put tools in `app/Ai/Tools/&lt;Integration&gt;` named `*Tool` extending `BaseTool`, implementing only `description()`, `risk()`, `execute()`, and `schema()`. Never call an upstream API from a Destructive tool — return `['type','target_service','payload']` and let `BaseTool` queue an `ActionRequest`. Per-run state lives in a container-bound `*RunContext`, since the SDK resolves tools fresh from the container.
