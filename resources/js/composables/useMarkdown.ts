import DOMPurify from 'dompurify';
import { Marked } from 'marked';

const marked = new Marked({
    gfm: true,
    breaks: true,
    pedantic: false,
});

export function renderMarkdown(source: string): string {
    if (!source) {
        return '';
    }

    // DOMPurify.sanitize() returns its input UNCHANGED when no DOM is
    // available (`isSupported` is false in the SSR Node process) — which
    // would feed raw marked output into v-html as stored XSS the moment any
    // page server-renders markdown. Render nothing on the server; the
    // client re-renders with real sanitization after hydration.
    if (!DOMPurify.isSupported) {
        return '';
    }

    const html = marked.parse(source, { async: false }) as string;

    return DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target', 'rel'],
    });
}

export function useMarkdown(): { render: (source: string) => string } {
    return { render: renderMarkdown };
}
