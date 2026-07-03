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

    const html = marked.parse(source, { async: false }) as string;

    return DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target', 'rel'],
    });
}

export function useMarkdown(): { render: (source: string) => string } {
    return { render: renderMarkdown };
}
