import type { AiReasoningLevel } from '@/typefinder';

export type BenchmarkScoringKind = 'actions' | 'prices' | 'judge';

type JsonScalar = string | number | boolean | null;

type JsonShallow = JsonScalar | JsonScalar[] | { [key: string]: JsonScalar };

/**
 * Arguments are arbitrary JSON, but Inertia's form typing cannot follow a
 * recursive type, so it is spelled out two levels deep (the runtime value may
 * nest further).
 */
export type JsonValue =
    JsonShallow | JsonShallow[] | { [key: string]: JsonShallow };

export type JsonObject = { [key: string]: JsonValue };

export type BenchmarkFixture = {
    tool: string;
    arguments: JsonObject;
    result: string;
};

export type BenchmarkExpectedAction = {
    tool: string;
    arguments: JsonObject;
};

export type BenchmarkExpectedPrice = {
    provider: string;
    model: string;
    input_per_mtok: number | string;
    output_per_mtok: number | string;
    cache_read_per_mtok: number | string;
    cache_write_per_mtok: number | string;
};

export type BenchmarkHistoryTurn = {
    role: 'user' | 'assistant';
    content: string;
};

export type BenchmarkCaseInput = {
    prompt?: string;
    history?: BenchmarkHistoryTurn[];
    mode?: string | null;
    event_key?: string | null;
    providers?: string[];
};

export type BenchmarkCaseScoring = {
    type: BenchmarkScoringKind;
    expected?: Array<BenchmarkExpectedAction | BenchmarkExpectedPrice>;
    tolerance_percent?: number;
    rubric_note?: string | null;
    pass_threshold?: number;
};

/** A saved case, or a draft built from an AI usage record (no id). */
export type BenchmarkCasePayload = {
    id?: number;
    task: string;
    name?: string;
    tags?: string[];
    notes?: string | null;
    input: BenchmarkCaseInput;
    fixtures: BenchmarkFixture[];
    scoring: BenchmarkCaseScoring;
    source_invocation_id?: string | null;
    source_prompt_tokens?: number | null;
    source_completion_tokens?: number | null;
    source_truncated?: boolean;
};

export type BenchmarkTaskOption = {
    name: string;
    value: string;
};

export type BenchmarkCaseRow = {
    id: number;
    task: string;
    task_label: string;
    name: string;
    tags: string[];
    fixture_count: number;
    scoring_type: BenchmarkScoringKind | null;
    source_invocation_id: string | null;
    updated_at: string | null;
};

export type BenchmarkRunStatus =
    | 'pending'
    | 'running'
    | 'completed'
    | 'stopped_cost_cap'
    | 'cancelled'
    | 'failed';

/** A provider / model / reasoning selection (candidate or judge). */
export type BenchmarkSelection = {
    provider: string;
    model: string;
    reasoning: string | null;
};

export type BenchmarkRunRow = {
    id: number;
    name: string;
    status: BenchmarkRunStatus;
    status_label: string;
    case_count: number;
    candidate_count: number;
    estimate_usd: number;
    cost_usd: number;
    created_by: string | null;
    created_at: string | null;
};

export type BenchmarkRunCaseOption = {
    id: number;
    task: string;
    task_label: string;
    name: string;
    tags: string[];
};

export type BenchmarkEstimate = {
    total_usd: number;
    judge_usd: number;
    rows: Array<{
        case_id: number;
        candidate_index: number;
        usd: number;
        priced: boolean;
    }>;
    unpriced: string[];
};

export type BenchmarkRunDetail = {
    id: number;
    name: string;
    status: BenchmarkRunStatus;
    status_label: string;
    candidates: BenchmarkSelection[];
    judge: BenchmarkSelection | null;
    estimate_usd: number;
    cost_usd: number;
    cost_cap_usd: number | null;
    started_at: string | null;
    finished_at: string | null;
    counts: {
        total: number;
        pending: number;
        running: number;
        ok: number;
        failed: number;
        skipped: number;
    };
};

/** `queued` and `pending` are the transient re-judging states. */
export type BenchmarkJudgeStatus =
    'none' | 'ok' | 'failed' | 'queued' | 'pending';

export type BenchmarkMatrixCandidate = {
    index: number;
    provider: string | null;
    model: string | null;
    reasoning: string | null;
    reasoning_label: string;
    self_judged: boolean;
    results: number;
    ok: number;
    failed: number;
    skipped: number;
    avg_score: number | null;
    pass_rate: number | null;
    cost_per_case: number | null;
    latency_p50: number | null;
    latency_p95: number | null;
    tool_misses: number;
};

export type BenchmarkMatrixCell = {
    candidate_index: number;
    result_id: number;
    status: string;
    score: number | null;
    passed: boolean | null;
    judge_status: BenchmarkJudgeStatus;
};

export type BenchmarkMatrixTask = {
    task: string;
    task_label: string;
    candidates: BenchmarkMatrixCandidate[];
    best: {
        avg_score: number | null;
        pass_rate: number | null;
        cost_per_case: number | null;
        latency_p50: number | null;
    };
    cases: Array<{
        case_id: number | null;
        case_name: string;
        cells: BenchmarkMatrixCell[];
    }>;
};

export type BenchmarkToolLogEntry = {
    tool: string;
    arguments: JsonObject;
    result: string | null;
    hit: boolean;
    write?: true;
    /** A destructive call advisory mode refused; never an intended action. */
    refused?: true;
};

export type BenchmarkResultDetail = {
    case_id: number | null;
    case_name: string;
    case_task: string;
    scoring: BenchmarkCaseScoring | null;
    prompt: string | null;
    results: Array<{
        result_id: number;
        candidate_index: number;
        status: string;
        output: string | null;
        intended_actions: BenchmarkExpectedAction[];
        score: number | null;
        passed: boolean | null;
        judge_status: BenchmarkJudgeStatus;
        score_detail: {
            score_10?: number;
            rationale?: string;
            matched?: unknown[];
            missing?: unknown[];
            extra?: unknown[];
            mismatches?: unknown[];
            error?: string;
        };
        error: string | null;
        tool_log: BenchmarkToolLogEntry[];
        latency_ms: number | null;
        cost_usd: number;
    }>;
};

/** A model picker row's value: the reasoning level is a typed enum value. */
export type BenchmarkSelectionForm = {
    provider: string;
    model: string;
    reasoning: AiReasoningLevel | null;
};

export type BenchmarkModelCapabilities = Record<
    string,
    {
        supports_reasoning: boolean | null;
        levels: AiReasoningLevel[] | null;
    }
>;
