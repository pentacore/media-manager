import type { HostCheckType } from './types';

/** One param input of the check form; `key` is the param name the server validates as `params.<key>`. */
export interface CheckField {
    key: string;
    label: string;
    kind: 'text' | 'number' | 'engine';
    required: boolean;
    placeholder?: string;
    hint?: string;
}

/** The form fields per check type, matching `HostCheckType::paramRules()` and the agent contract. */
export const CHECK_FIELDS: Record<HostCheckType, CheckField[]> = {
    mount_present: [
        {
            key: 'path',
            label: 'Mount point',
            kind: 'text',
            required: true,
            placeholder: '/mnt/backups',
        },
        {
            key: 'fstype',
            label: 'Filesystem type',
            kind: 'text',
            required: false,
            placeholder: 'nfs4',
        },
        {
            key: 'source',
            label: 'Source',
            kind: 'text',
            required: false,
            placeholder: 'nas:/backups',
        },
    ],
    path_on_mount: [
        {
            key: 'path',
            label: 'Path',
            kind: 'text',
            required: true,
            placeholder: '/mnt/backups/daily',
        },
        {
            key: 'mount',
            label: 'Expected mount',
            kind: 'text',
            required: true,
            placeholder: '/mnt/backups',
        },
    ],
    file_freshness: [
        {
            key: 'path',
            label: 'Directory or file',
            kind: 'text',
            required: true,
            placeholder: '/mnt/backups/daily',
        },
        {
            key: 'pattern',
            label: 'File pattern',
            kind: 'text',
            required: false,
            placeholder: '*.tar.gz',
            hint: 'Default *',
        },
        {
            key: 'warn_hours',
            label: 'Warn after (hours)',
            kind: 'number',
            required: true,
            placeholder: '26',
        },
        {
            key: 'crit_hours',
            label: 'Critical after (hours)',
            kind: 'number',
            required: true,
            placeholder: '48',
        },
    ],
    db_ping: [
        { key: 'engine', label: 'Engine', kind: 'engine', required: true },
        {
            key: 'host',
            label: 'Host',
            kind: 'text',
            required: true,
            placeholder: '127.0.0.1',
        },
        {
            key: 'port',
            label: 'Port',
            kind: 'number',
            required: true,
            placeholder: '5432',
        },
        {
            key: 'timeout_seconds',
            label: 'Timeout (seconds)',
            kind: 'number',
            required: false,
            placeholder: '5',
            hint: 'Default 5',
        },
    ],
    mountpoint_protected: [
        {
            key: 'path',
            label: 'Mount point',
            kind: 'text',
            required: true,
            placeholder: '/mnt/backups',
            hint: 'The directory under the mount must be chattr +i',
        },
    ],
};

/** An empty param set for `type`, or the stored values of its own keys when editing. */
export function checkParams(
    type: HostCheckType,
    stored: Record<string, unknown> = {},
): Record<string, string | number | null> {
    return Object.fromEntries(
        CHECK_FIELDS[type].map((field) => {
            const value = stored[field.key];

            return [
                field.key,
                typeof value === 'string' || typeof value === 'number'
                    ? value
                    : null,
            ];
        }),
    );
}
