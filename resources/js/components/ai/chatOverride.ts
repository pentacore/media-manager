import type { ChatOverride, ModelOptions } from './types';

/** Whether the conversation overrides the default model or reasoning. */
export function isOverridden(override: ChatOverride): boolean {
    return override.model !== null || override.reasoning !== null;
}

/**
 * The provider and model a turn runs on: the override's, else the chat's
 * defaults ('' before the options load). The one source for the desktop
 * model chip and the model options body.
 */
export function effectiveModel(
    override: ChatOverride,
    options: ModelOptions | null,
): { provider: string; model: string } {
    return {
        provider: override.provider ?? options?.defaults.provider ?? '',
        model: override.model ?? options?.defaults.model ?? '',
    };
}
