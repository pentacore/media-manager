const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w185';

export function tmdbPosterUrl(path: string | null): string | null {
    if (!path) {
        return null;
    }

    return `${TMDB_IMAGE_BASE}${path}`;
}
