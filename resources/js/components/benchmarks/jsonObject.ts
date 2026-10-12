import type { JsonObject } from './types';

export type ParsedJsonObject =
    { value: JsonObject; error: null } | { value: null; error: string };

/** Turns a JSON object into editable text; PHP encodes an empty object as `[]`. */
export function formatJsonObject(value: unknown): string {
    if (
        value === null ||
        typeof value !== 'object' ||
        Array.isArray(value) ||
        Object.keys(value).length === 0
    ) {
        return '{}';
    }

    return JSON.stringify(value, null, 2);
}

/** Parses the text of an arguments textarea; blank text means an empty object. */
export function parseJsonObject(text: string): ParsedJsonObject {
    if (text.trim() === '') {
        return { value: {}, error: null };
    }

    try {
        const parsed: unknown = JSON.parse(text);

        if (
            parsed === null ||
            typeof parsed !== 'object' ||
            Array.isArray(parsed)
        ) {
            return { value: null, error: 'Arguments must be a JSON object.' };
        }

        return { value: parsed as JsonObject, error: null };
    } catch {
        return { value: null, error: 'Arguments are not valid JSON.' };
    }
}
