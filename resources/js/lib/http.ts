/** The page's CSRF token from the `csrf-token` meta tag ('' when absent). */
export function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/** What a request that never reached the server says, instead of the browser's "Failed to fetch". */
export const NETWORK_ERROR_MESSAGE = 'Could not reach the server. Try again.';

/**
 * A same-origin JSON request carrying the CSRF and XHR headers Laravel
 * expects. Resolves with the decoded body; on a non-2xx response it throws
 * an Error with the server's `message`, or "Request failed (<status>)". A
 * network failure throws an Error with {@link NETWORK_ERROR_MESSAGE}, so a
 * caller can show `e.message` without leaking the browser's raw text.
 */
export async function jsonRequest<T>(
    method: string,
    url: string,
    body?: unknown,
): Promise<T> {
    let response: Response;

    try {
        response = await fetch(url, {
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
    } catch (cause) {
        // fetch() rejects with a TypeError ("Failed to fetch", "Load failed",
        // "NetworkError when attempting to fetch resource.") when the
        // request never got an answer.
        if (cause instanceof TypeError) {
            throw new Error(NETWORK_ERROR_MESSAGE, { cause });
        }

        throw cause;
    }

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
