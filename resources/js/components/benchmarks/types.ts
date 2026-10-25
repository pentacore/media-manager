import type { AiReasoningLevel, BenchmarkJudgeStatus } from '@/typefinder';

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

export type BenchmarkConversationMode = 'context' | 'scripted';

export type BenchmarkCaseAttachment = {
    id: number;
    turn: number | null;
    name: string;
    mime_type: string;
    size: number;
};

export type BenchmarkDraftAttachment = {
    chat_attachment_id: number;
    turn: number | null;
    name: string;
    mime_type: string;
    size: number;
};

/** One row of a turn's attachment list in the form. */
export type BenchmarkAttachmentEntry =
    | { kind: 'saved'; id: number; name: string; size: number }
    | { kind: 'draft'; chatAttachmentId: number; name: string; size: number }
    | { kind: 'new'; file: File; name: string; size: number };

export type BenchmarkHistoryTurnForm = BenchmarkHistoryTurn & {
    attachments: BenchmarkAttachmentEntry[];
};

export type BenchmarkCaseInput = {
    prompt?: string;
    history?: BenchmarkHistoryTurn[];
    mode?: string | null;
    conversation?: BenchmarkConversationMode | null;
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
    attachments?: BenchmarkCaseAttachment[];
    draft_attachments?: BenchmarkDraftAttachment[];
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
    attachment_count: number;
    scripted: boolean;
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

export type BenchmarkRegression = {
    candidate: BenchmarkSelection;
    paired_cases: number;
    metrics: Array<{
        metric: 'pass_rate' | 'avg_score' | 'error_rate';
        before: number;
        after: number;
    }>;
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
    schedule: { id: number; name: string } | null;
    created_by: string | null;
    created_at: string | null;
    comparable: boolean;
};

export type BenchmarkCompareOption = {
    id: number;
    name: string;
    created_at: string | null;
    previous_scheduled: boolean;
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
    schedule: { id: number; name: string } | null;
    baseline: { id: number; name: string } | null;
    regressions: BenchmarkRegression[] | null;
    started_at: string | null;
    finished_at: string | null;
    counts: {
        total: number;
        pending: number;
        running: number;
        /** Running results whose claim went stale (their worker died). */
        stalled: number;
        ok: number;
        failed: number;
        skipped: number;
    };
};

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
    /** Results with a score; avg score and pass rate cover only these. */
    scored: number;
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
    /** A queued or pending re-judge untouched past the stale window. */
    judge_stale: boolean;
    /** A running result whose claim went stale (its worker died). */
    stale: boolean;
};

export type BenchmarkMatrixTask = {
    task: string;
    task_label: string;
    candidates: BenchmarkMatrixCandidate[];
    /** The most results any candidate has scored; only candidates at it can be best by score or pass rate. */
    max_scored: number;
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

export type BenchmarkTranscriptTurn = {
    role: 'user' | 'assistant';
    content: string;
    attachments: string[];
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
        judge_stale: boolean;
        stale: boolean;
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
        transcript: BenchmarkTranscriptTurn[];
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

export type BenchmarkScheduleOutcomeValue =
    | 'launched'
    | 'skipped_no_cases'
    | 'skipped_no_candidates'
    | 'skipped_running'
    | 'skipped_over_cap'
    | 'failed';

export type BenchmarkScheduleRow = {
    id: number;
    name: string;
    task: string;
    task_label: string;
    cadence: string;
    enabled: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    last_outcome: BenchmarkScheduleOutcomeValue | null;
    last_outcome_label: string | null;
    last_message: string | null;
    latest_run: {
        id: number;
        name: string;
        status: BenchmarkRunStatus;
        status_label: string;
    } | null;
};

export type BenchmarkScheduleForm = {
    name: string;
    task: string;
    case_tags: string[];
    judge: BenchmarkSelectionForm;
    cost_cap_usd: number;
    frequency: string;
    time_of_day: string;
    weekday: number | null;
    day_of_month: number | null;
    pass_rate_threshold: number;
    score_threshold: number;
    enabled: boolean;
};

export type BenchmarkComparisonMetric =
    'pass_rate' | 'avg_score' | 'cost_per_case' | 'latency_p50' | 'error_rate';

export type BenchmarkComparisonStats = {
    results: number;
    ok: number;
    failed: number;
    skipped: number;
    scored: number;
    avg_score: number | null;
    pass_rate: number | null;
    cost_per_case: number | null;
    latency_p50: number | null;
    latency_p95: number | null;
    tool_misses: number;
    error_rate: number | null;
    self_judged: boolean;
};

export type BenchmarkComparisonDelta = Record<
    BenchmarkComparisonMetric,
    number | null
>;

export type BenchmarkComparisonCell = {
    result_id: number;
    status: string;
    score: number | null;
    passed: boolean | null;
    judge_status: BenchmarkJudgeStatus;
};

export type BenchmarkComparisonRun = {
    id: number;
    name: string;
    status: BenchmarkRunStatus;
    status_label: string;
    created_at: string | null;
    judge: BenchmarkSelection | null;
    judge_label: string | null;
    schedule_name: string | null;
    is_baseline: boolean;
    candidates: BenchmarkSelection[];
};

export type BenchmarkComparisonMember = {
    run_id: number;
    candidate_index: number;
};

export type BenchmarkComparisonLine = {
    key: string;
    label: string;
    paired: boolean;
    members: BenchmarkComparisonMember[];
};

export type BenchmarkComparisonCaseRow = {
    case_id: number;
    case_name: string;
    line_key: string;
    changed: boolean;
    /** Aligned with the runs; null where the line has no result. */
    cells: Array<BenchmarkComparisonCell | null>;
};

export type BenchmarkComparisonTask = {
    task: string;
    task_label: string;
    common_cases: number;
    excluded_cases: number;
    rows: Array<{
        line_key: string;
        /** Aligned with the runs; null where the line has no result. */
        figures: Array<BenchmarkComparisonStats | null>;
        /** Aligned with the runs; the baseline's is always null. */
        deltas: Array<BenchmarkComparisonDelta | null>;
    }>;
    cases: BenchmarkComparisonCaseRow[];
};

export type BenchmarkComparison = {
    runs: BenchmarkComparisonRun[];
    lines: BenchmarkComparisonLine[];
    /** Manual pairs the server applied, "41.0~57.1". */
    pairs: string[];
    tasks: BenchmarkComparisonTask[];
    score_source: {
        current: string;
        options: Array<{ key: string; label: string; coverage: number }>;
    };
    judges_differ: boolean;
};

export type BenchmarkNeutralRejudgeSummary = {
    id: number;
    status: 'running' | 'completed' | 'stopped_cost_cap' | 'cancelled';
    status_label: string;
    judge_key: string;
    judge_label: string;
    estimate_usd: number;
    cost_cap_usd: number;
    cost_usd: number;
    total: number;
    done: number;
    failed: number;
};

export type BenchmarkPickableRun = {
    id: number;
    name: string;
    created_at: string | null;
    task_labels: string[];
};

export type BenchmarkJudgeOptions = {
    models: Record<string, string[]>;
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: BenchmarkModelCapabilities;
    reasoningProviders: string[];
    judgeDefault: { provider: string; model: string; reasoning: string };
};

export type BenchmarkCompareOutput = {
    result_id: number;
    run_id: number;
    run_name: string;
    candidate_label: string;
    status: string;
    output: string | null;
    error: string | null;
    score: number | null;
    passed: boolean | null;
    rationale: string | null;
    judge_label: string | null;
};

export type BenchmarkNeutralEstimate = {
    total_usd: number;
    results: number;
    unpriced: string[];
};
