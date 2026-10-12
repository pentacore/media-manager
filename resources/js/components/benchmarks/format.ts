import type { BenchmarkJudgeStatus } from '@/typefinder';

/** Whether a judge status is a re-judge still waiting for or getting its score (`queued` and `pending` are transient). */
export function isRejudging(judgeStatus: BenchmarkJudgeStatus): boolean {
    return judgeStatus === 'queued' || judgeStatus === 'pending';
}

/** A 0–1 score as the 0–10 figure shown in the UI. */
export function formatScore(score: number | null | undefined): string {
    return score === null || score === undefined
        ? '—'
        : (score * 10).toFixed(1);
}

export function formatPercent(rate: number | null | undefined): string {
    return rate === null || rate === undefined
        ? '—'
        : `${Math.round(rate * 100)}%`;
}

export function formatUsd(amount: number | null | undefined): string {
    if (amount === null || amount === undefined) {
        return '—';
    }

    return `$${amount.toFixed(amount !== 0 && amount < 0.01 ? 4 : 2)}`;
}

export function formatLatency(milliseconds: number | null | undefined): string {
    return milliseconds === null || milliseconds === undefined
        ? '—'
        : `${(milliseconds / 1000).toFixed(1)}s`;
}

/** "provider · model · reasoning", leaving out an unset part and the provider default reasoning. */
export function candidateLabel(candidate: {
    provider?: string | null;
    model: string | null;
    reasoning?: string | null;
    reasoning_label?: string;
}): string {
    const reasoning =
        candidate.reasoning_label ??
        (candidate.reasoning && candidate.reasoning !== 'provider_default'
            ? candidate.reasoning
            : null);

    return [
        candidate.provider,
        candidate.model,
        reasoning && reasoning !== 'Provider default' ? reasoning : null,
    ]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}
