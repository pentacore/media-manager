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
