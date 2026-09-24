---
paths:
  - 'app/Services/AiUsage/Pricing/**'
---

# Pricing

## New-model creation is gated per provider by the auto-create list
Every automatic pricing write (feed and verifier agent) creates a row only when `RefreshScope::allowsCreate()` passes, which reads `AiSettings::autoCreatePricingProviders()` (config default `mediamanager.ai.pricing.auto_create_providers`, all but openrouter). Never hardcode a provider-specific create rule again. A skipped new model returns `WriteOutcome::CreateDisabled` (not `Rejected`) and is counted as `create_disabled` per provider so audits don't read it as bad data. Any new `WriteOutcome` case must be added to the coordinator's exhaustive `match`, or the feed phase throws `UnhandledMatchError`.
