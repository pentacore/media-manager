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

export type JsonObjectMessages = { notObject: string; invalid: string };

export const ARGUMENT_MESSAGES: JsonObjectMessages = {
    notObject: 'Arguments must be a JSON object.',
    invalid: 'Arguments are not valid JSON.',
};

export const PAYLOAD_MESSAGES: JsonObjectMessages = {
    notObject: 'The payload must be a JSON object.',
    invalid: 'The payload is not valid JSON.',
};

export const EVENT_PAYLOAD_MESSAGES: JsonObjectMessages = {
    notObject: 'The event payload must be a JSON object.',
    invalid: 'The event payload is not valid JSON.',
};

/** Parses the text of a JSON textarea; blank text means an empty object. */
export function parseJsonObject(
    text: string,
    messages: JsonObjectMessages = ARGUMENT_MESSAGES,
): ParsedJsonObject {
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
            return { value: null, error: messages.notObject };
        }

        return { value: parsed as JsonObject, error: null };
    } catch {
        return { value: null, error: messages.invalid };
    }
}
