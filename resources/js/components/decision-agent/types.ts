import type { SecondOpinionEngine, SecondOpinionMode } from '@/typefinder';

/** A per-event second-opinion override in the page props and form payload. */
export type SecondOpinionEventOverride = {
    event_key: string;
    mode: SecondOpinionMode | null;
    engine: SecondOpinionEngine | null;
    jev_threshold: number | null;
};
