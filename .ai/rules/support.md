---
paths:
  - 'app/Support/**'
---

# Support

## Support is cross-cutting glue only
Reserve `app/Support` for integration-free helpers: `final` static utility classes with `public const` keys, or small injectable infrastructure services. Keep upstream-API and domain logic in `app/Services`.

## UpstreamErrorText fallbacks name the right culprit
`UpstreamErrorText::sanitize($message, $limit, $emptyFallback)` returns `$emptyFallback` when nothing usable is left. It defaults to `UPSTREAM_EMPTY_FALLBACK` ("The upstream service returned an error without a usable description."), which is right for media-service clients. Agent-run failure paths that store the text (`RunDecisionAgent`'s decision summary, `RecordFailedAgentRun`, `RecordFailedOverAttemptUsage`) pass `AGENT_EMPTY_FALLBACK` ("The agent failed without a usable description."). `ExecuteActionRequest` passes `ACTION_EMPTY_FALLBACK` ("The action failed without a usable description.") for a local exception (`execution_failed` from its `Throwable` catch, `job_failed`) and keeps the upstream default for media-service failures. A new caller whose failure is not a media service's passes the matching fallback instead of letting the default blame one. Text that only reaches a `Log::` context needs no fallback choice.
