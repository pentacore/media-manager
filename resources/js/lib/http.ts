/** The page's CSRF token from the `csrf-token` meta tag ('' when absent). */
export function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/**
 * A same-origin JSON request carrying the CSRF and XHR headers Laravel
 * expects. Resolves with the decoded body; on a non-2xx response it throws
 * an Error with the server's `message`, or "Request failed (<status>)".
 */
export async function jsonRequest<T>(
    method: string,
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const data = await response
            .json()
            .catch(() => ({}) as Record<string, unknown>);
        const message =
            typeof data.message === 'string'
                ? data.message
                : `Request failed (${response.status})`;

        throw new Error(message);
    }

    return (await response.json()) as T;
}
