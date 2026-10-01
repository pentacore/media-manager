import type { PricingSourceKey } from './types';

export const SOURCE_LABELS: Record<PricingSourceKey, string> = {
    seed: 'Seed data',
    models_dev: 'Models.dev',
    first_party: 'First-party source',
    manual: 'Manual',
    legacy: 'Legacy',
    openrouter: 'OpenRouter',
    litellm: 'LiteLLM',
    xai_api: 'xAI API',
    feed_consensus: 'Models.dev + LiteLLM',
};
