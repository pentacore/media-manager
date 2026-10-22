import BenchmarkComparisonController from '@/actions/App/Http/Controllers/Admin/BenchmarkComparisonController';
import { formatLatency, formatPercent, formatScore, formatUsd } from './format';
import type {
    BenchmarkComparison,
    BenchmarkComparisonCell,
    BenchmarkComparisonLine,
    BenchmarkComparisonMember,
    BenchmarkComparisonMetric,
} from './types';

export const ORIGINAL_SCORES = 'original';

/** What the comparison URL says: runs, baseline, manual pairs and score source. */
export type CompareState = {
    runs: number[];
    baseline: number;
    pairs: string[];
    scores: string;
};

export function compareState(comparison: BenchmarkComparison): CompareState {
    const runs = comparison.runs.map((run) => run.id);

    return {
        runs,
        baseline: comparison.runs.find((run) => run.is_baseline)?.id ?? runs[0],
        pairs: comparison.pairs,
        scores: comparison.score_source.current,
    };
}

export function compareUrl(state: CompareState): string {
    const query: Record<string, string | number> = {
        runs: state.runs.join(','),
        baseline: state.baseline,
    };

    if (state.pairs.length > 0) {
        query.pairs = state.pairs.join(',');
    }

    if (state.scores !== ORIGINAL_SCORES) {
        query.scores = state.scores;
    }

    return BenchmarkComparisonController.show.url({ query });
}

export const COMPARE_METRICS: Array<{
    value: BenchmarkComparisonMetric;
    label: string;
    higherIsBetter: boolean;
}> = [
    { value: 'pass_rate', label: 'Pass rate', higherIsBetter: true },
    { value: 'avg_score', label: 'Avg score', higherIsBetter: true },
    { value: 'cost_per_case', label: 'Cost / case', higherIsBetter: false },
    { value: 'latency_p50', label: 'p50 latency', higherIsBetter: false },
    { value: 'error_rate', label: 'Error rate', higherIsBetter: false },
];

export function formatMetric(
    metric: BenchmarkComparisonMetric,
    value: number | null,
): string {
    switch (metric) {
        case 'pass_rate':
        case 'error_rate':
            return formatPercent(value);
        case 'avg_score':
            return formatScore(value);
        case 'cost_per_case':
            return formatUsd(value);
        case 'latency_p50':
            return formatLatency(value);
    }
}

/** "▲12" / "▼3.0": percentage points, judge points, dollars or seconds; '' when unchanged or unknown. */
export function formatDelta(
    metric: BenchmarkComparisonMetric,
    delta: number | null,
): string {
    if (delta === null || Math.abs(delta) < 1e-9) {
        return '';
    }

    const arrow = delta > 0 ? '▲' : '▼';
    const size = Math.abs(delta);

    switch (metric) {
        case 'pass_rate':
        case 'error_rate':
            return `${arrow}${Math.round(size * 100)}`;
        case 'avg_score':
            return `${arrow}${(size * 10).toFixed(1)}`;
        case 'cost_per_case':
            return `${arrow}${formatUsd(size)}`;
        case 'latency_p50':
            return `${arrow}${formatLatency(size)}`;
    }
}

export function deltaTone(
    metric: BenchmarkComparisonMetric,
    delta: number | null,
): 'better' | 'worse' | 'same' {
    if (delta === null || Math.abs(delta) < 1e-9) {
        return 'same';
    }

    const higherIsBetter =
        COMPARE_METRICS.find((entry) => entry.value === metric)
            ?.higherIsBetter ?? true;

    return delta > 0 === higherIsBetter ? 'better' : 'worse';
}

function memberKey(member: BenchmarkComparisonMember): string {
    return `${member.run_id}.${member.candidate_index}`;
}

export function pairKey(
    line: BenchmarkComparisonLine,
    other: BenchmarkComparisonLine,
): string {
    return `${memberKey(line.members[0])}~${memberKey(other.members[0])}`;
}

/** Two lines can merge when no run has a candidate on both. */
export function canPair(
    line: BenchmarkComparisonLine,
    other: BenchmarkComparisonLine,
): boolean {
    return (
        line.key !== other.key &&
        line.members.every(
            (member) =>
                !other.members.some(
                    (otherMember) => otherMember.run_id === member.run_id,
                ),
        )
    );
}

/** The manual pairs left after splitting a paired line back into its parts. */
export function withoutLinePairs(
    pairs: string[],
    line: BenchmarkComparisonLine,
): string[] {
    const keys = line.members.map(memberKey);

    return pairs.filter(
        (pair) => !pair.split('~').some((side) => keys.includes(side)),
    );
}

export function compareCellText(cell: BenchmarkComparisonCell): string {
    if (cell.status !== 'ok') {
        return cell.status;
    }

    const verdict = cell.passed === null ? '' : cell.passed ? '✓ ' : '✗ ';

    return cell.score === null
        ? `${verdict}unscored`.trim()
        : `${verdict}${formatScore(cell.score)}`;
}

export function compareCellClass(cell: BenchmarkComparisonCell): string {
    if (cell.status === 'failed') {
        return 'text-destructive';
    }

    if (cell.status !== 'ok' || cell.passed === null) {
        return 'text-muted-foreground';
    }

    return cell.passed ? 'text-success' : 'text-destructive';
}
