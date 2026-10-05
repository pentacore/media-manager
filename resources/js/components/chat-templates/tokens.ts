import type { ChatTemplateVariableKind } from './types';

/**
 * Matches well-formed tokens only, mirroring ChatTemplateParser's name rule.
 * Malformed ones are left for the server preview to report.
 */
const TOKEN_PATTERN = /\{\{([a-z][a-z0-9_]{0,31})(?::[a-z]+(?:,[a-z]+)*)?\}\}/g;

/** Distinct variable names in order of first appearance. */
export function extractTokenNames(body: string): string[] {
    const names: string[] = [];

    for (const match of body.matchAll(TOKEN_PATTERN)) {
        const name = match[1];

        if (!names.includes(name)) {
            names.push(name);
        }
    }

    return names;
}

/** "anime_title" → "Anime title". */
export function humanizeVariableName(name: string): string {
    const spaced = name.replaceAll('_', ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/** The name rule ChatTemplateParser and the save request enforce. */
export const VARIABLE_NAME_PATTERN = /^[a-z][a-z0-9_]{0,31}$/;

/** Parts a series or movie token can ask for, in the order the help lists them. */
export const LIBRARY_PARTS = ['title', 'year', 'id'] as const;

export type LibraryPart = (typeof LIBRARY_PARTS)[number];

/** Worked example shown in the editor help and the empty template list. */
export const EXAMPLE_TEMPLATE_BODY =
    'Check {{anime:title,year}} S{{season}}E{{episode}} for subtitles';
export const EXAMPLE_TEMPLATE_RESULT =
    'Check Frieren (2023) S1E7 for subtitles';

/**
 * "{{name}}" or "{{name:part,part}}". Only the title, or nothing, is the
 * same as no parts, so it stays the plain token.
 */
export function buildToken(
    name: string,
    parts: readonly string[] = [],
): string {
    const isPlain =
        parts.length === 0 || (parts.length === 1 && parts[0] === 'title');

    return isPlain ? `{{${name}}}` : `{{${name}:${parts.join(',')}}}`;
}

/**
 * Replaces text[start, end) with insert and returns the new text with the
 * caret placed right after the inserted text. Out-of-range or reversed
 * offsets are clamped.
 */
export function insertAtSelection(
    text: string,
    start: number,
    end: number,
    insert: string,
): { text: string; caret: number } {
    const from = Math.min(Math.max(start, 0), text.length);
    const to = Math.min(Math.max(end, from), text.length);

    return {
        text: text.slice(0, from) + insert + text.slice(to),
        caret: from + insert.length,
    };
}

/**
 * A free name for a new variable of the given type: series prefers "anime",
 * then "series"; every other type uses its own name. Taken names get "_2",
 * "_3", … appended to the type's name.
 */
export function suggestVariableName(
    type: ChatTemplateVariableKind,
    takenNames: readonly string[],
): string {
    const preferred: string[] =
        type === 'series' ? ['anime', 'series'] : [type];
    const free = preferred.find((name) => !takenNames.includes(name));

    if (free !== undefined) {
        return free;
    }

    const base = preferred[preferred.length - 1];

    for (let suffix = 2; ; suffix++) {
        const candidate = `${base}_${suffix}`;

        if (!takenNames.includes(candidate)) {
            return candidate;
        }
    }
}
