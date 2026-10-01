---
paths:
  - 'app/Services/AiUsage/Pricing/**'
---

# Pricing

## New-model creation is gated per provider by the auto-create list
Every automatic pricing write (feed and verifier agent) creates a row only when `RefreshScope::allowsCreateModel()` passes: the provider is on `AiSettings::autoCreatePricingProviders()` (config default `mediamanager.ai.pricing.auto_create_providers`, all but openrouter), or the model is in `InUsePricingModels` (usage history plus models picked in AI settings). Never hardcode a provider-specific create rule again. A skipped new model returns `WriteOutcome::CreateDisabled` (not `Rejected`) and is counted as `create_disabled` per provider so audits don't read it as bad data. Any new `WriteOutcome` case must be added to the exhaustive `match` in `PricingFeedPhase::writeProviderCandidates()`, or the feed phase throws `UnhandledMatchError`. The one exception is an admin's explicit catalog pick: `RefreshScope::forExplicitCreates($provider, $models)` lets exactly those models be created (used by `AiModelCatalogController::store`); never widen it to a provider-level create.
