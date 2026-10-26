export type DisagreementKind = 'false_alarm' | 'miss';

export type AgreementBucket = 'day' | 'week';

export const LOW_SAMPLE = 5;

export interface AgreementTotals {
    verdicts: number;
    reviewed: number;
    agreed_approved: number;
    agreed_rejected: number;
    disagreed_rejected: number;
    disagreed_approved: number;
    failed: number;
    rejected_by_verifier: number;
    awaiting_review: number;
    auto_run: number;
    unattributed: number;
    skipped: number;
}

export interface AgreementRates {
    agreement: number | null;
    holds_right: number | null;
    coverage: number | null;
}

export interface AgreementBreakdownRow {
    key: string;
    label: string;
    verdicts: number;
    reviewed: number;
    agreed: number;
    agreement: number | null;
    false_alarms: number;
    misses: number;
}

export interface AgreementDailyPoint {
    date: string;
    reviewed: number;
    agreed: number;
    agreement: number | null;
}

export interface AgreementDisagreement {
    id: number;
    kind: DisagreementKind;
    event: string;
    action_type: string;
    reason: string | null;
    answered_by: string;
    reviewed_at: string | null;
    invocation_id: string | null;
}

export interface SecondOpinionAgreement {
    totals: AgreementTotals;
    rates: AgreementRates;
    by_event: AgreementBreakdownRow[];
    by_action_type: AgreementBreakdownRow[];
    by_answered_by: AgreementBreakdownRow[];
    daily_bucket: AgreementBucket;
    daily: AgreementDailyPoint[];
    disagreements: AgreementDisagreement[];
    overridden_events: string[];
}

export function percent(rate: number | null): string {
    return rate === null ? '—' : `${Math.round(rate * 100)}%`;
}
