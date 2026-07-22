<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Settings\AiSettings;

/**
 * Per-run allowlist that governs which providers and models an automatic
 * pricing write may touch.
 *
 * Canonical provider identity is Laravel AI identity: every provider passed in
 * or checked is canonicalized (for example `google` becomes `gemini`) before
 * comparison, so callers may use either the upstream or canonical spelling. A
 * provider with no canonical mapping is unsupported and is never allowed.
 *
 * This object is per-run state and must never be cached in a singleton or
 * static property under Octane or long-running queue workers.
 */
final readonly class RefreshScope
{
    /**
     * Fallback provider map used until the `mediamanager.ai.pricing.providers`
     * config is present. Keys are upstream identifiers, values are canonical
     * Laravel AI identifiers.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_PROVIDER_MAP = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'google' => 'gemini',
        'xai' => 'xai',
        'deepseek' => 'deepseek',
        'mistral' => 'mistral',
        'groq' => 'groq',
        'cohere' => 'cohere',
        'openrouter' => 'openrouter',
    ];

    /**
     * @param  list<string>|null  $providers  Canonical provider allowlist, or null for all supported providers.
     * @param  array<string, list<string>|null>|null  $providerModels  Canonical provider => allowed models, or null when not model-scoped. A per-provider null value means every model of that provider is allowed (a provider-level wildcard within an otherwise model-scoped map).
     */
    private function __construct(
        private ?array $providers,
        private ?array $providerModels,
        private bool $openRouterCreateAllowed = false,
    ) {}

    /**
     * Allow every supported provider and model.
     */
    public static function all(): self
    {
        return new self(providers: null, providerModels: null);
    }

    /**
     * Restrict writes to the given providers (any model).
     *
     * @param  list<string>  $providers
     */
    public static function forProviders(array $providers): self
    {
        $canonical = [];

        foreach ($providers as $provider) {
            $id = self::canonicalize($provider);

            if ($id !== null) {
                $canonical[$id] = true;
            }
        }

        return new self(providers: array_keys($canonical), providerModels: null);
    }

    /**
     * Restrict writes to specific models under specific providers.
     *
     * @param  array<string, list<string>>  $providerModels
     */
    public static function forProviderModels(array $providerModels): self
    {
        $canonical = [];

        foreach ($providerModels as $provider => $models) {
            $id = self::canonicalize($provider);

            if ($id === null) {
                continue;
            }

            $canonical[$id] = array_values(array_unique([
                ...($canonical[$id] ?? []),
                ...$models,
            ]));
        }

        return new self(providers: array_keys($canonical), providerModels: $canonical);
    }

    /**
     * Restrict writes to a mixed set of targets in one scope: each provider may
     * be bound to an exact model list OR opened wide (a null value = every model
     * of that provider). This lets one scope carry provider-level wildcard
     * targets alongside exact-model targets without widening the exact ones —
     * the fix for a mixed fallback that would otherwise promote every provider
     * to provider-wide writes because one provider needed the whole feed.
     *
     * @param  array<string, list<string>|null>  $targets  Provider (upstream or canonical) => exact models, or null for the whole provider.
     */
    public static function forTargets(array $targets): self
    {
        $canonical = [];

        foreach ($targets as $provider => $models) {
            $id = self::canonicalize($provider);

            if ($id === null) {
                continue;
            }

            // A provider-level wildcard is sticky: once a provider is opened
            // wide it stays wide even if another target names specific models.
            // array_key_exists (not ??) so an existing null wildcard is seen.
            $alreadyWildcard = array_key_exists($id, $canonical) && $canonical[$id] === null;

            if ($alreadyWildcard || $models === null) {
                $canonical[$id] = null;

                continue;
            }

            $canonical[$id] = array_values(array_unique([
                ...($canonical[$id] ?? []),
                ...$models,
            ]));
        }

        return new self(providers: array_keys($canonical), providerModels: $canonical);
    }

    /**
     * Whether the scope permits writing the given provider/model pair.
     */
    public function allowsWrite(string $provider, string $model): bool
    {
        $id = self::canonicalize($provider);

        if ($id === null) {
            return false;
        }

        if ($this->providers === null) {
            return true;
        }

        if (! in_array($id, $this->providers, true)) {
            return false;
        }

        // Not model-scoped at all: any model of an allowed provider is fine.
        if ($this->providerModels === null) {
            return true;
        }

        // Model-scoped: a null entry is a provider-level wildcard; a list
        // restricts to exactly those models. array_key_exists (not ??) so a
        // deliberate null wildcard is not collapsed to an empty deny-list.
        $models = array_key_exists($id, $this->providerModels) ? $this->providerModels[$id] : [];

        return $models === null || in_array($model, $models, true);
    }

    /**
     * Whether the scope permits touching the given provider at all.
     */
    public function allowsProvider(string $provider): bool
    {
        $id = self::canonicalize($provider);

        if ($id === null) {
            return false;
        }

        if ($this->providers === null) {
            return true;
        }

        return in_array($id, $this->providers, true);
    }

    /**
     * Whether OpenRouter rows may be created (as opposed to only updated).
     * Defaults to false: OpenRouter never expands the catalog automatically.
     */
    public function isOpenRouterCreateAllowed(): bool
    {
        return $this->openRouterCreateAllowed;
    }

    /**
     * Whether this scope was explicitly narrowed to a provider allowlist. An
     * unbounded scope ({@see all()}) means nobody named specific providers, so
     * conservative defaults (for example the core fallback provider set) apply.
     */
    public function isBounded(): bool
    {
        return $this->providers !== null;
    }

    /**
     * The exact model allowlist this scope binds to the given provider, or null
     * when the scope does not restrict that provider to specific models.
     *
     * @return list<string>|null
     */
    public function modelsFor(string $provider): ?array
    {
        $id = self::canonicalize($provider);

        if ($id === null || $this->providerModels === null) {
            return null;
        }

        return $this->providerModels[$id] ?? null;
    }

    /**
     * Resolve an upstream or canonical provider identifier to its canonical
     * Laravel AI identity (for example `google` becomes `gemini`), or null when
     * the provider is unsupported. Shared with the writer so canonicalization
     * has one implementation across every automatic pricing path.
     */
    public static function canonicalProvider(string $provider): ?string
    {
        return self::canonicalize($provider);
    }

    /**
     * Resolve an upstream or canonical provider identifier to its canonical
     * Laravel AI identity, or null when the provider is unsupported or on the
     * operator-configured ignore list.
     */
    private static function canonicalize(string $provider): ?string
    {
        $provider = strtolower(trim($provider));

        if ($provider === '') {
            return null;
        }

        /** @var array<string, string> $map */
        $map = config('mediamanager.ai.pricing.providers', self::DEFAULT_PROVIDER_MAP);

        $canonical = null;

        if (isset($map[$provider])) {
            $canonical = $map[$provider];
        } elseif (in_array($provider, $map, true)) {
            $canonical = $provider;
        }

        if ($canonical === null || in_array($canonical, self::ignoredProviders($map), true)) {
            return null;
        }

        return $canonical;
    }

    /**
     * The operator-configured provider ignore list, resolved to canonical
     * identities. The provider map is the code-level whitelist; this list is
     * the ops-level opt-out layered on top — an ignored provider is treated as
     * unsupported by every automatic pricing path (feed sync, agent fallback,
     * CLI scope validation) while its existing rows stay last-known-good.
     *
     * The list is sourced from the runtime {@see AiSettings} (which falls back
     * to the `mediamanager.ai.pricing.ignored_providers` config default until
     * an admin saves one). Resolving the per-request settings instance inside
     * this static method is deliberate: no per-run state is cached statically,
     * so it is safe under Octane and long-running workers.
     *
     * @param  array<string, string>  $map
     * @return list<string>
     */
    private static function ignoredProviders(array $map): array
    {
        $ignored = resolve(AiSettings::class)->ignoredPricingProviders();

        $canonical = [];

        foreach ($ignored as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            if (isset($map[$entry])) {
                $canonical[] = $map[$entry];
            } elseif (in_array($entry, $map, true)) {
                $canonical[] = $entry;
            }
        }

        return $canonical;
    }
}
