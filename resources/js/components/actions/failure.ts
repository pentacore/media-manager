import type { ConfirmOptions } from '@/composables/useConfirm';
import type { ActionRequestResource } from '@/typefinder/resources/ActionRequestResource';

/** An action's `result`: the full result for admins, the member-safe subset otherwise. */
export type ActionResult = ActionRequestResource['result'];

const REASON_LABELS = new Map<string, string>([
    ['needs_reconciliation', 'Needs reconciliation'],
    ['execution_failed', 'Execution failed'],
    ['retries_exhausted', 'Retries exhausted'],
    ['job_failed', 'Job failed'],
    ['no_executor', 'No executor for this action type'],
]);

const SERVICE_LABELS = new Map<string, string>([
    ['sonarr', 'Sonarr'],
    ['radarr', 'Radarr'],
    ['seerr', 'Seerr'],
    ['emby', 'Emby'],
    ['whisparr', 'Whisparr'],
    ['bazarr', 'Bazarr'],
    ['sabnzbd', 'SABnzbd'],
    ['prowlarr', 'Prowlarr'],
]);

function failureReason(result: ActionResult): string | null {
    const reason = result?.['reason'];

    return typeof reason === 'string' && reason !== '' ? reason : null;
}

/** The human label for a failed action's reason code; "Failed" when none was recorded. */
export function failureReasonLabel(result: ActionResult): string {
    const reason = failureReason(result);

    if (reason === null) {
        return 'Failed';
    }

    const words = reason.replace(/_/g, ' ');

    return (
        REASON_LABELS.get(reason) ??
        words.charAt(0).toUpperCase() + words.slice(1)
    );
}

/** The sanitized failure message, which only an admin's full result carries. */
export function failureMessage(result: ActionResult): string | null {
    const message = result?.['message'];

    return typeof message === 'string' && message.trim() !== ''
        ? message
        : null;
}

/**
 * Whether the last run may already have changed the target service.
 * needs_reconciliation means "unknown" unless its writer said otherwise: a
 * request approved but never started sets indeterminate: false, because
 * nothing reached the target. A broadcast result has no indeterminate key,
 * so its needs_reconciliation counts as unknown.
 */
export function isUncertainOutcome(result: ActionResult): boolean {
    if (result?.['indeterminate'] === true) {
        return true;
    }

    return (
        failureReason(result) === 'needs_reconciliation' &&
        result?.['indeterminate'] !== false
    );
}

/** A target_service code as people know it ("sonarr" → "Sonarr"). */
export function serviceLabel(service: string): string {
    return SERVICE_LABELS.get(service.toLowerCase()) ?? service;
}

export function uncertainOutcomeWarning(
    request: ActionRequestResource,
): string {
    return `Outcome unknown — check ${serviceLabel(request.target_service)} before retrying.`;
}

/** The shared confirm dialog's question before retrying an uncertain outcome. */
export function retryConfirmation(
    request: ActionRequestResource,
): ConfirmOptions {
    const service = serviceLabel(request.target_service);

    return {
        title: 'Retry an action with an unknown outcome?',
        description: `${failureReasonLabel(request.result)}. ${uncertainOutcomeWarning(request)} The last run may already have reached ${service}, and a retry sends the change again.`,
        confirmLabel: 'Retry anyway',
    };
}

/**
 * Folds a live ActionRequestStatusChanged result into a row's result. The
 * broadcast carries only the member-safe keys, so while the status is
 * unchanged it is merged over the existing result and an admin keeps the
 * message and outcome flag the page props brought. A new status means a new
 * result, which replaces the old one. A null result (a retry cleared it)
 * clears the row's; a payload without a result changes nothing unless the
 * status moved.
 */
export function mergeLiveResult(
    current: ActionResult,
    live: ActionResult | undefined,
    statusUnchanged: boolean,
): ActionResult {
    if (live === undefined) {
        return statusUnchanged ? current : null;
    }

    if (live === null) {
        return null;
    }

    return statusUnchanged && current !== null
        ? { ...current, ...live }
        : { ...live };
}
