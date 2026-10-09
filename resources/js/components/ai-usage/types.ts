export interface Totals {
    total_invocations: number;
    total_tool_calls: number;
    total_tokens: number;
    total_cost: string;
}

export interface AggregateRow {
    key: string | null;
    invocations: number;
    total_tokens: number;
    total_cost: string;
}

export interface RecentRow {
    id: number;
    created_at: string;
    provider: string | null;
    model: string | null;
    prompt_tokens: number;
    completion_tokens: number;
    tool_calls_count: number;
    total_tokens: number;
    cost: string;
    conversation_id: string | null;
    status: string;
    kind: string;
    tier_position: number | null;
    error_message: string | null;
    user_name: string | null;
}

export interface ToolStatRow {
    tool_class: string;
    calls: number;
    failures: number;
    p50_ms: number | null;
    p95_ms: number | null;
}

export interface KindOption {
    value: string;
    label: string;
}

export interface ChildRun {
    id: number;
    agent_class: string | null;
    model: string | null;
    status: string;
    total_tokens: number;
}

export interface PricedModel {
    provider: string;
    model: string;
    input_per_mtok: string;
    output_per_mtok: string;
    cache_read_per_mtok: string;
    cache_write_per_mtok: string;
    reasoning_per_mtok: string;
}

export interface ScenarioRates {
    input: number;
    output: number;
    cache_read: number;
    cache_write: number;
    reasoning: number;
}

export interface BreakdownLine {
    label: string;
    tokens: number;
    rate: number;
    cost: number;
}

export interface InvocationDetail {
    record: {
        id: number;
        invocation_id: string;
        agent_class: string | null;
        provider: string | null;
        model: string | null;
        prompt_tokens: number;
        completion_tokens: number;
        cache_read_input_tokens: number;
        cache_write_input_tokens: number;
        reasoning_tokens: number;
        tool_calls_count: number;
        prompt_text: string | null;
        response_text: string | null;
        price_source: string | null;
        conversation_id: string | null;
        status: string;
        kind: string;
        error_message: string | null;
        created_at: string | null;
    };
    user: { id: number; name: string } | null;
    tools: Array<{
        id: number;
        tool_class: string;
        tool_invocation_id: string | null;
        status: string;
        error_code: string | null;
        duration_ms: number | null;
        created_at: string | null;
    }>;
    children: ChildRun[];
    rates: {
        source: 'snapshot' | 'catalog' | 'unpriced';
        input_per_mtok: number;
        output_per_mtok: number;
        cache_read_per_mtok: number;
        cache_write_per_mtok: number;
        reasoning_per_mtok: number;
    };
    breakdown: BreakdownLine[];
    total_cost: number;
    scenario_breakdown: BreakdownLine[] | null;
    scenario_total_cost: number | null;
}

export type WindowKey =
    'today' | '24h' | '7d' | '30d' | '90d' | 'week' | 'month' | 'year' | 'all';

/** The AI usage ledger's model-tier filter; null shows every run. */
export type TierFilter = 'first' | 'fell_through';

export interface WindowOption {
    value: WindowKey;
    label: string;
}

export interface FreePoolRow {
    id: number;
    name: string;
    period: 'daily' | 'weekly' | 'monthly';
    unified: boolean;
    documentation_url: string | null;
    free_input: number | null;
    free_output: number | null;
    free_total: number | null;
    used_input: number;
    used_output: number;
    used_total: number;
    models: Array<{
        provider: string;
        model: string;
        used_input: number;
        used_output: number;
    }>;
}

export interface RateLimitStatusRow {
    provider: string;
    model: string;
    limits: Array<{
        metric: 'requests' | 'tokens';
        period: 'minute' | 'hour' | 'day';
        limit_value: number;
        used: number;
    }>;
}
