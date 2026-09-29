<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\OpenRouterSort;

/**
 * OpenRouter upstream routing preferences, sent as the `provider` object of
 * every OpenRouter request. Backed by the AppSettings store; defaults fall
 * back to config('mediamanager.ai.openrouter.*').
 */
class OpenRouterSettings
{
    public const string SORT_KEY = 'ai.openrouter.sort';

    public const string DENY_DATA_COLLECTION_KEY = 'ai.openrouter.deny_data_collection';

    public const string ALLOW_FALLBACKS_KEY = 'ai.openrouter.allow_fallbacks';

    public const string ORDER_KEY = 'ai.openrouter.order';

    public const string IGNORE_KEY = 'ai.openrouter.ignore';

    public function __construct(private readonly AppSettings $appSettings) {}

    /**
     * How OpenRouter ranks upstreams, or null for its default load balancing.
     */
    public function sort(): ?OpenRouterSort
    {
        return OpenRouterSort::tryFrom((string) ($this->appSettings->get(self::SORT_KEY) ?? config('mediamanager.ai.openrouter.sort')));
    }

    public function setSort(?OpenRouterSort $openRouterSort): void
    {
        $this->appSettings->set(self::SORT_KEY, $openRouterSort?->value);
    }

    /**
     * Whether only upstreams that neither store nor train on prompts are used.
     */
    public function denyDataCollection(): bool
    {
        return (bool) ($this->appSettings->get(self::DENY_DATA_COLLECTION_KEY) ?? config('mediamanager.ai.openrouter.deny_data_collection', false));
    }

    public function setDenyDataCollection(?bool $deny): void
    {
        $this->appSettings->set(self::DENY_DATA_COLLECTION_KEY, $deny);
    }

    /**
     * Whether OpenRouter may fall back to other upstreams for the same model.
     */
    public function allowFallbacks(): bool
    {
        return (bool) ($this->appSettings->get(self::ALLOW_FALLBACKS_KEY) ?? config('mediamanager.ai.openrouter.allow_fallbacks', true));
    }

    public function setAllowFallbacks(?bool $allow): void
    {
        $this->appSettings->set(self::ALLOW_FALLBACKS_KEY, $allow);
    }

    /**
     * Upstream slugs OpenRouter tries first, in order.
     *
     * @return list<string>
     */
    public function order(): array
    {
        return $this->slugList(self::ORDER_KEY, 'order');
    }

    /**
     * @param  array<int, mixed>|null  $slugs
     */
    public function setOrder(?array $slugs): void
    {
        $this->appSettings->set(self::ORDER_KEY, $slugs === null ? null : self::normalizeSlugs($slugs));
    }

    /**
     * Upstream slugs OpenRouter must never use.
     *
     * @return list<string>
     */
    public function ignore(): array
    {
        return $this->slugList(self::IGNORE_KEY, 'ignore');
    }

    /**
     * @param  array<int, mixed>|null  $slugs
     */
    public function setIgnore(?array $slugs): void
    {
        $this->appSettings->set(self::IGNORE_KEY, $slugs === null ? null : self::normalizeSlugs($slugs));
    }

    /**
     * Trim, lowercase and de-duplicate upstream slugs, dropping blanks.
     *
     * @param  array<int, mixed>  $slugs
     * @return list<string>
     */
    public static function normalizeSlugs(array $slugs): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $slug): string => strtolower(trim((string) $slug)), $slugs),
            static fn (string $slug): bool => $slug !== '',
        )));
    }

    /**
     * @return list<string>
     */
    private function slugList(string $key, string $configKey): array
    {
        $stored = $this->appSettings->get($key);

        if (! is_array($stored)) {
            $stored = (array) config(sprintf('mediamanager.ai.openrouter.%s', $configKey), []);
        }

        return self::normalizeSlugs($stored);
    }
}
