export interface ServiceTypeOption {
    value: string;
    label: string;
}

export interface ArrConnectionOption {
    id: number;
    type: 'sonarr' | 'radarr';
    name: string;
}

export type DiskMetric = 'free' | 'used' | 'both';

export interface EditableConnection {
    id: number;
    type: { value: string } | string;
    name: string;
    url: string;
    external_url: string | null;
    api_key_set: boolean;
    webhook_token_set: boolean;
    webhook_url: string;
    supports_webhook_configuration: boolean;
    is_active: boolean;
    disk: {
        mode: 'all' | 'selected' | 'sum';
        paths: string[];
        display: Record<string, DiskMetric>;
    };
    hidden_categories?: string[];
    sabnzbd_webhook_script?: string | null;
    whisparr_version?: string;
    sonarr_connection_id: number | null;
    radarr_connection_id: number | null;
}

export interface ProwlarrIndexer {
    id: number;
    name: string;
    enable: boolean;
    priority: number;
    implementation?: string;
}

export interface DiskPath {
    path: string;
    label: string | null;
}

export interface SonarrRootFolder {
    root_folder_id: number;
    path: string;
    scope: 'anime' | 'tv' | null;
}

export interface ArrTag {
    id: number;
    label: string;
}

export interface TestConnectionResponse {
    success: boolean;
    message: string;
    version?: string;
}
