import type { ServerStatus } from '@/typefinder';

export type { ServerStatus };

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
}
