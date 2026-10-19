import { isUncertainOutcome } from './failure';
import type { ActionRequestRow } from './types';

/** The Action Queue's status tabs, in display order. */
export const ACTION_TABS: { id: string; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'pending', label: 'Pending' },
    { id: 'approved', label: 'Approved' },
    { id: 'executing', label: 'Executing' },
    { id: 'completed', label: 'Completed' },
    { id: 'failed', label: 'Failed' },
    { id: 'rejected', label: 'Rejected' },
];

export function isDestructive(type: string | null | undefined): boolean {
    return /delete|remove|destroy|replace/.test(type ?? '');
}

export function svcId(name: string | null | undefined): string {
    if (!name) {
        return '';
    }

    const t = name.toLowerCase();

    if (t.includes('jellyseerr') || t.includes('seerr')) {
        return 'seerr';
    }

    if (t.includes('sonarr')) {
        return 'sonarr';
    }

    if (t.includes('radarr')) {
        return 'radarr';
    }

    if (t.includes('emby')) {
        return 'emby';
    }

    if (t.includes('prowlarr')) {
        return 'prowlarr';
    }

    return t;
}

/**
 * The reviewer's shared reason, stored in full on a rejected request. A
 * request the second opinion rejected has none here: its verdict block
 * already shows the reason.
 */
export function rejectionReason(row: ActionRequestRow): string | null {
    if (row.result?.reason === 'second_opinion_rejected') {
        return null;
    }

    const reason = row.result?.rejection_reason;

    return typeof reason === 'string' && reason !== '' ? reason : null;
}

/**
 * The reviewer who rejected a request (the reviewer stores themselves in
 * `approved_by`). A second-opinion rejection has no human reviewer.
 */
export function rejectedBy(row: ActionRequestRow): string | null {
    if (
        row.status !== 'rejected' ||
        row.result?.reason === 'second_opinion_rejected'
    ) {
        return null;
    }

    return row.approved_by ?? null;
}

export function payloadTitle(row: ActionRequestRow): string {
    if (row.title) {
        return row.title;
    }

    const t = row.payload?.['title'] ?? row.payload?.['name'];

    return typeof t === 'string' && t.length > 0
        ? t
        : `${row.type.replace(/_/g, ' ')}`;
}

export function payloadDetail(row: ActionRequestRow): string {
    if (row.description) {
        return row.description;
    }

    const detail = row.payload?.['detail'] ?? row.payload?.['summary'];

    return typeof detail === 'string' ? detail : '';
}

/**
 * Only agent- and chat-originated rationale is LLM-written; manual-dialog and
 * automatic-check replacements store server-written text in the same field.
 */
export function showsAiReasoning(row: ActionRequestRow): boolean {
    return (
        !!row.agent_rationale &&
        (row.origin === 'agent' || row.origin === 'chat')
    );
}

export function replacementLanguages(row: ActionRequestRow): string {
    const languages = row.payload?.['required_languages'];

    return Array.isArray(languages) && languages.length > 0
        ? languages.filter((item) => typeof item === 'string').join(', ')
        : '—';
}

export function replacementConfidence(row: ActionRequestRow): string {
    const confidence = row.payload?.['confidence'];

    return typeof confidence === 'number' ? `${confidence}%` : '—';
}

export function replacementSelectionMode(row: ActionRequestRow): string {
    const mode = row.payload?.['selection_mode'];

    return typeof mode === 'string' ? mode : '—';
}

export function replacementAffectedFiles(row: ActionRequestRow): number {
    const count = row.payload?.['affected_file_count'];

    return typeof count === 'number' ? count : 0;
}

export function replacementEvidence(row: ActionRequestRow): string {
    const rules = row.payload?.['matched_rules'];

    if (!Array.isArray(rules)) {
        return '—';
    }

    const names = rules
        .map((rule) =>
            typeof rule === 'string'
                ? rule
                : rule !== null &&
                    typeof rule === 'object' &&
                    typeof (rule as Record<string, unknown>)['name'] ===
                        'string'
                  ? ((rule as Record<string, unknown>)['name'] as string)
                  : '',
        )
        .filter((name) => name.length > 0);

    return names.length > 0 ? names.join(', ') : '—';
}

export function replacementIsSeasonPack(row: ActionRequestRow): boolean {
    return row.payload?.['season_pack'] === true;
}

export type PillVariant = 'default' | 'ok' | 'warn' | 'danger' | 'info';

export function attemptVariant(status: string): PillVariant {
    switch (status) {
        case 'verified':
            return 'ok';
        case 'failed':
            return 'danger';
        case 'needs_attention':
            return 'warn';
        case 'imported':
            return 'default';
        default:
            return 'info';
    }
}

/** A failed row's badge: `warn` for an unknown outcome, `danger` otherwise. */
export function failureBadgeVariant(row: ActionRequestRow): PillVariant {
    return isUncertainOutcome(row.result) ? 'warn' : 'danger';
}

export function pipelineState(
    row: ActionRequestRow,
    stage: 'created' | 'approved' | 'executing' | 'done',
): 'done' | 'failed' | 'active' | 'pending' {
    const s = row.status;

    if (stage === 'created') {
        return 'done';
    }

    if (stage === 'approved') {
        return s === 'pending'
            ? 'pending'
            : s === 'rejected'
              ? 'failed'
              : 'done';
    }

    if (stage === 'executing') {
        return ['executing', 'completed', 'failed'].includes(s)
            ? 'done'
            : 'pending';
    }

    if (s === 'completed') {
        return 'done';
    }

    if (s === 'failed') {
        return 'failed';
    }

    return 'pending';
}
