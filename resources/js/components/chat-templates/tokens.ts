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
