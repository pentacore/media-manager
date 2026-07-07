interface ArrImage {
    coverType: string;
    remoteUrl?: string | null;
    url?: string | null;
}

export function arrPosterUrl(images: ArrImage[]): string | null {
    const poster = images.find((image) => image.coverType === 'poster');

    return poster?.remoteUrl ?? poster?.url ?? null;
}
