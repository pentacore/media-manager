interface ArrImage {
    coverType: string;
    remoteUrl?: string | null;
    url?: string | null;
}

export function arrPosterUrl(images: ArrImage[]): string | null {
    const poster = images.find((image) => image.coverType === 'poster');

    return poster?.remoteUrl ?? poster?.url ?? null;
}

/** Human-readable size of an *arr byte count; "—" when nothing is on disk. */
export function formatSize(bytes: number): string {
    if (!bytes || bytes <= 0) {
        return '—';
    }

    const tb = bytes / 1024 ** 4;

    if (tb >= 1) {
        return `${tb.toFixed(1)} TB`;
    }

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb.toFixed(1)} GB`;
    }

    return `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}
