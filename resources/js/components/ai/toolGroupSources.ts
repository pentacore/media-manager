import type { ToolGroupSource } from './types';

/** How each source reads next to a tool group chip. */
export const SOURCE_LABELS: Record<ToolGroupSource, string> = {
    pinned: 'Pinned',
    assistant: 'Assistant',
    carried_over: 'Carried over',
    picked: 'Picked',
};

/** The chip colours for a group's source: accent pinned, success assistant. */
export function sourceClasses(source: ToolGroupSource): string {
    if (source === 'pinned') {
        return 'border-accent/28 bg-accent/10 text-accent';
    }

    return source === 'assistant'
        ? 'border-success/30 bg-success/10 text-success'
        : 'border-border bg-bg-elev text-muted-foreground';
}
