---
paths:
  - 'app/Services/Actions/**'
---

# Actions

## Every action request carries a server-verified ActionDescription
ActionOrchestrator::dispatch()/dispatchFromAgent() require an ActionDescription. Word arr/Seerr/Emby/download types with ActionDescriber (Bazarr: SubtitleOperationDescriber, replacements: ReplacementRequestBuilder) and add only the "why" via because() plus trigger facts. Target names come from ActionTargets (index → live client); LLM text is only a fallback_title, which marks the description unverified and forces Pending. agent_rationale stays in the payload and never becomes the title/description. Read-only lookups for descriptions are allowed from Destructive tools. Also applies to app/Ai/**, app/Services/**/*WebhookHandler.php, and app/Http/Controllers/**. Replacement and Bazarr describers must not put caller-supplied free text (e.g. a model's reason) into details.

## Conditional ActionRequest transitions log themselves
Query-builder status updates on ActionRequest (the Approved→Executing claim in ExecuteActionRequest, actions:reconcile-stuck) bypass ActionRequestObserver. After such an update succeeds, refresh the model and call ActionRequestActivityLogger::statusChanged() so the audit trail is complete. resolvePinned() aborts on a deactivated pinned connection of the requested type.
