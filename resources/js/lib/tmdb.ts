const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w185';

export function tmdbPosterUrl(path: string | null): string | null {
    if (!path) {
        return null;
    }

    return `${TMDB_IMAGE_BASE}${path}`;
}

const TMDB_BACKDROP_BASE = 'https://image.tmdb.org/t/p/w780';

export function tmdbBackdropUrl(path: string | null): string | null {
    return path ? `${TMDB_BACKDROP_BASE}${path}` : null;
}
