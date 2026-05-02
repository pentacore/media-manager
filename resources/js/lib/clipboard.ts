/**
 * Copy text to the system clipboard.
 *
 * Production deployments served over plain HTTP (or inside an iframe
 * without the `clipboard-write` permission) expose `navigator.clipboard`
 * as `undefined`, which is what causes the
 * `Cannot read properties of undefined (reading 'writeText')` crash.
 *
 * We try the async Clipboard API first (only works in secure contexts —
 * https://, localhost, or file://), then fall back to a hidden textarea
 * + `document.execCommand('copy')`, which is deprecated but still works
 * in every evergreen browser when called from a user gesture.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
    if (
        typeof navigator !== 'undefined' &&
        navigator.clipboard &&
        typeof navigator.clipboard.writeText === 'function' &&
        window.isSecureContext
    ) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // fall through to legacy path
        }
    }

    if (typeof document === 'undefined') {
        return false;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '0';
    textarea.style.left = '0';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.appendChild(textarea);

    try {
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, text.length);
        const ok = document.execCommand('copy');

        return ok;
    } catch {
        return false;
    } finally {
        document.body.removeChild(textarea);
    }
}
