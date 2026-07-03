<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

/**
 * Per-request flag deciding whether AI usage should be priced against the
 * provider's batch tier. The AI SDK has no batch API yet, so nothing flips
 * this on in production — it exists so a future batch pipeline (e.g. embedding
 * backfills routed through a provider batch endpoint) can resolve this context
 * and set `enabled` around its dispatch, having usage snapshot the `batch_*`
 * rates.
 *
 * Scoped rather than static: this app runs on Octane/FrankenPHP long-lived
 * workers, where a static toggle would leak across requests, race under
 * concurrency, and stay poisoned if a throw skipped its reset. Octane rebuilds
 * scoped bindings per request, so a fresh instance is guaranteed each request.
 */
final class BatchPricingContext
{
    public bool $enabled = false;
}
