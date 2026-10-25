import type {
    HostCheckStatus,
    HostCheckType,
    IncidentSeverity,
    IncidentStatus,
    ServerMetricRange,
    ServerStatus,
} from '@/typefinder';

export type {
    HostCheckStatus,
    HostCheckType,
    IncidentSeverity,
    IncidentStatus,
    ServerMetricRange,
    ServerStatus,
};

/** One row of the servers index (`ServerController::row()`). */
export interface ServerRow {
    id: number;
    name: string;
    hostname: string | null;
    status: ServerStatus;
    status_label: string;
    last_report_at: string | null;
    agent_version: string | null;
    agent_outdated: boolean;
    open_incidents_count: number;
}

/**
 * The one-time install instructions flashed after adding a server or
 * regenerating its token (`EnrollmentInstructions::for()`). The token lives
 * only in that flash, never in page props.
 */
export interface EnrollmentInstructions {
    server_id: number;
    token: string;
    expires_at: string;
    platform_url: string;
    debian_command: string;
    unraid_plugin_url: string;
}

/** The outside watcher's state; the push URL itself never reaches the page. */
export interface WatcherState {
    push_url_set: boolean;
    push_url_hint: string | null;
    /** Where the URL in use comes from: saved through this form, or MEDIAMANAGER_UPTIME_KUMA_PUSH_URL. */
    push_url_source: 'saved' | 'environment' | null;
    environment_push_url_set: boolean;
}

/** A `{value, label}` pair for an enum select. */
export interface EnumOption<T extends string = string> {
    value: T;
    label: string;
}

/** The server page's facts (`ServerController::facts()`). */
export interface ServerDetail {
    id: number;
    uuid: string;
    name: string;
    hostname: string | null;
    os: string | null;
    kernel: string | null;
    arch: string | null;
    agent_version: string | null;
    agent_outdated: boolean;
    status: ServerStatus;
    status_label: string;
    enrolled_at: string | null;
    last_report_at: string | null;
    report_interval_seconds: number;
    uptime_seconds: number | null;
    enrollment_expires_at: string | null;
    /** Why the platform last refused a report (a validation summary, no payload). */
    last_report_error: string | null;
    last_report_error_at: string | null;
}

/** One filesystem of the latest report; its byte and percent fields are null while it is unresponsive. */
export interface LatestFilesystem {
    mount: string;
    fstype: string | null;
    unresponsive: boolean;
    used_percent: number | null;
    used_bytes: number | null;
    total_bytes: number | null;
    available_bytes: number | null;
}

export interface LatestContainer {
    name: string;
    state: string;
    health: string | null;
    restart_count: number;
}

/** The latest report's figures (`ServerController::latest()`); null until the agent reports. */
export interface LatestReport {
    cpu_percent: number;
    load: number[];
    memory_used_percent: number | null;
    filesystems: LatestFilesystem[];
    /** Null when the host has no systemd. */
    failed_units: string[] | null;
    /** Null when Docker is absent or did not answer. */
    containers: LatestContainer[] | null;
}

export interface ServerCheckRow {
    id: number;
    uuid: string;
    type: HostCheckType;
    type_label: string;
    label: string;
    params: Record<string, unknown>;
    is_enabled: boolean;
    last_status: HostCheckStatus | null;
    last_status_label: string | null;
    last_message: string | null;
    last_observed: Record<string, unknown> | null;
    last_evaluated_at: string | null;
}

/**
 * One incident row. `escalated_at` is also set when a severity rises again
 * without a fresh alert, so the page never shows it as "escalated at".
 */
export interface ServerIncidentRow {
    id: number;
    key: string;
    severity: IncidentSeverity;
    status: IncidentStatus;
    title: string;
    detail: string | null;
    opened_at: string;
    last_seen_at: string;
    escalated_at: string | null;
    resolved_at: string | null;
}

/** One chart bucket: its ISO start and its average, null when no sample fell in it. */
export interface MetricPoint {
    at: string;
    value: number | null;
}

/**
 * `ServerMetricRepository::series()`: percentages. A series with data has
 * every bucket of the window (at most 120); one without data is empty.
 */
export interface MetricSeries {
    cpu: MetricPoint[];
    memory: MetricPoint[];
    filesystems: Array<{ mount: string; points: MetricPoint[] }>;
}
