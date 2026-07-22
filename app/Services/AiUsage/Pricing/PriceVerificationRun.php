<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\WriteOutcome;

/**
 * Per-agent-run receipt and outcome ledger shared between the fetch tool and
 * the write tool for a single verifier phase.
 *
 * Two responsibilities:
 *
 * 1. Fetch receipts — {@see WebFetchTool} records the FINAL fetched URL of
 *    every successful fetch here. The write tool then refuses to stamp a row as
 *    first-party verified unless its `source_url` matches a recorded receipt
 *    exactly (see {@see requiresReceipts()}). This closes the H2 gap where the
 *    upsert tool trusted any `source_url` the model invented.
 * 2. Write outcomes — {@see UpsertModelPriceTool} records every
 *    {@see WriteOutcome} keyed by canonical provider AND by the exact
 *    provider:model pair, flagging which of them are VERIFICATION-GRADE
 *    (receipt-backed with both primary rates supplied). The coordinator
 *    resolves fallback/verification targets exclusively from the
 *    verification-grade tallies — provider-native (receiptless) outcomes are
 *    kept for audit only — instead of trusting mere prompt completion (H3).
 *
 * This object is per-run state and must never be cached in a singleton or
 * static property under Octane or long-running queue workers.
 */
final class PriceVerificationRun
{
    /**
     * Exact final URL of every successful fetch this run performed => the
     * canonical provider that URL's host belongs to (or null when the host is
     * unmapped). Used as provider-bound write verification receipts: a write is
     * only receipt-backed when the fetched URL matches AND its recorded provider
     * matches the candidate provider, so a fetch of one provider's page can
     * never authorize a write for another provider.
     *
     * @var array<string, string|null>
     */
    private array $receipts = [];

    /**
     * Canonical provider => WriteOutcome value => count. Records EVERY write
     * outcome (verification-grade or not) so the coordinator's audit tallies
     * reflect what the agent actually did.
     *
     * @var array<string, array<string, int>>
     */
    private array $outcomes = [];

    /**
     * "provider:model" => WriteOutcome value => count. Parallel to $outcomes so
     * an exact-model verification target resolves only through a real write of
     * that specific pair — a sibling model's write never satisfies it.
     *
     * @var array<string, array<string, int>>
     */
    private array $modelOutcomes = [];

    /**
     * Canonical provider => WriteOutcome value => count, restricted to
     * VERIFICATION-GRADE writes (the tool passed `firstPartyVerified: true` —
     * receipt-backed, both primary rates re-read). Only these resolve a
     * provider-level target: a provider-native (receiptless) write is audited in
     * {@see $outcomes} but never counts here, so under that path agent-phase
     * targets finalize unresolved.
     *
     * @var array<string, array<string, int>>
     */
    private array $verifiedOutcomes = [];

    /**
     * "provider:model" => WriteOutcome value => count, restricted to
     * verification-grade writes. Only these resolve an exact-model target.
     *
     * @var array<string, array<string, int>>
     */
    private array $verifiedModelOutcomes = [];

    /**
     * Whether writes must present a fetch receipt to be accepted. True only on
     * the custom {@see WebFetchTool} path, where every fetch is performed
     * locally and recorded — the only path whose writes are verification-grade
     * (anomaly-guard bypass and `pricing_verified_at` stamping). The
     * provider-native WebFetch flag path records no receipts (the provider
     * performs the fetch), so its writes are admitted on the host allowlist and
     * scheme validation alone as a best-effort refresh, never as verified.
     */
    private bool $requiresReceipts = false;

    /**
     * Mark this run as receipt-gated: writes must reference a URL this run
     * actually fetched. Called by the agent when it wires the custom fetch tool.
     */
    public function requireReceipts(): void
    {
        $this->requiresReceipts = true;
    }

    public function requiresReceipts(): bool
    {
        return $this->requiresReceipts;
    }

    /**
     * Record the final URL of a successful fetch as a write receipt, stamping
     * the canonical provider derived from the URL's host.
     */
    public function recordFetch(string $url): void
    {
        if ($url !== '') {
            $this->receipts[$url] = PricingSourceHosts::providerForUrl($url);
        }
    }

    /**
     * Whether this run fetched the exact given URL.
     */
    public function hasReceipt(?string $url): bool
    {
        return $url !== null && array_key_exists($url, $this->receipts);
    }

    /**
     * Whether this run fetched the exact given URL AND that fetch resolved to
     * the given canonical provider. This is the provider-bound receipt check the
     * write tool uses to reject a cross-provider source_url on the custom-fetch
     * path (for example an Anthropic receipt presented for an OpenAI write).
     */
    public function hasReceiptForProvider(?string $url, string $provider): bool
    {
        return $url !== null
            && array_key_exists($url, $this->receipts)
            && $this->receipts[$url] === $provider;
    }

    /**
     * Record a single write outcome for the given canonical provider, and —
     * when the model identifier is known — for the exact provider:model pair.
     *
     * @param  bool  $verificationGrade  True only when the write was receipt-backed
     *                                   AND re-read both primary rates. Only such
     *                                   outcomes resolve fallback/verification
     *                                   targets; every outcome is tallied for audit.
     */
    public function recordOutcome(string $provider, WriteOutcome $writeOutcome, ?string $model = null, bool $verificationGrade = false): void
    {
        $this->outcomes[$provider][$writeOutcome->value] = ($this->outcomes[$provider][$writeOutcome->value] ?? 0) + 1;

        $key = ($model !== null && $model !== '') ? $provider.':'.$model : null;

        if ($key !== null) {
            $this->modelOutcomes[$key][$writeOutcome->value] = ($this->modelOutcomes[$key][$writeOutcome->value] ?? 0) + 1;
        }

        if ($verificationGrade) {
            $this->verifiedOutcomes[$provider][$writeOutcome->value] = ($this->verifiedOutcomes[$provider][$writeOutcome->value] ?? 0) + 1;

            if ($key !== null) {
                $this->verifiedModelOutcomes[$key][$writeOutcome->value] = ($this->verifiedModelOutcomes[$key][$writeOutcome->value] ?? 0) + 1;
            }
        }
    }

    /**
     * The per-outcome counts recorded for the given canonical provider.
     *
     * @return array<string, int>
     */
    public function outcomesFor(string $provider): array
    {
        return $this->outcomes[$provider] ?? [];
    }

    /**
     * Whether the run recorded at least one VERIFICATION-GRADE write (created,
     * updated, unchanged, or their dry-run equivalents would_create /
     * would_update) for the given canonical provider. This is the signal the
     * coordinator uses to consider a provider-level fallback target resolved.
     * Receiptless provider-native writes never satisfy it, so under that path
     * the target finalizes unresolved.
     */
    public function providerHasVerifiedWrite(string $provider): bool
    {
        return $this->countsResolve($this->verifiedOutcomes[$provider] ?? []);
    }

    /**
     * Whether the run recorded a verification-grade write for the exact
     * provider:model pair. This is the only signal that resolves an exact-model
     * verification target (an anomaly ride-along or a model-pinned verify
     * target): a receiptless write, RejectedAnomalous, Locked, Rejected, or the
     * absence of any outcome leaves the target unverified.
     */
    public function modelHasVerifiedWrite(string $provider, string $model): bool
    {
        return $this->countsResolve($this->verifiedModelOutcomes[$provider.':'.$model] ?? []);
    }

    /**
     * Whether a per-outcome count map holds any outcome that resolves a target:
     * a real Created/Updated/Unchanged write, or — on an end-to-end dry run —
     * its WouldCreate/WouldUpdate equivalent (the dry verify still performs the
     * real first-party comparison).
     *
     * @param  array<string, int>  $counts
     */
    private function countsResolve(array $counts): bool
    {
        return ($counts[WriteOutcome::Created->value] ?? 0)
            + ($counts[WriteOutcome::Updated->value] ?? 0)
            + ($counts[WriteOutcome::Unchanged->value] ?? 0)
            + ($counts[WriteOutcome::WouldCreate->value] ?? 0)
            + ($counts[WriteOutcome::WouldUpdate->value] ?? 0) > 0;
    }

    /**
     * Total rows created across every provider this run — used to decide
     * whether the coordinator's row-count delta fallback is still needed.
     */
    public function totalCreated(): int
    {
        $total = 0;

        foreach ($this->outcomes as $outcome) {
            $total += $outcome[WriteOutcome::Created->value] ?? 0;
        }

        return $total;
    }
}
