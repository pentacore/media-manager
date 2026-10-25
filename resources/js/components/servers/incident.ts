import type { IncidentSeverity, IncidentStatus } from './types';

/** The pill variant for an incident: resolved is muted, open follows severity. */
export function incidentPillVariant(
    status: IncidentStatus,
    severity: IncidentSeverity,
): 'default' | 'danger' | 'warn' {
    if (status === 'resolved') {
        return 'default';
    }

    return severity === 'critical' ? 'danger' : 'warn';
}
