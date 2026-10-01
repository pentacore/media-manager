<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\UrlQueryRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Builds and masks the `{field: {from, to}}` diffs audit rows carry.
 *
 * A field whose name is a secret (any dot-path segment matching
 * SECRET_KEY_PATTERN, or a path listed for the audited subject) and any value
 * that is a URL with embedded credentials is recorded as `{changed: true}`
 * only. Every other string loses its URL query strings (they carry API keys),
 * and nested arrays mask their secret-named keys.
 */
final class AuditChanges
{
    /** Whole key segments, so `webhook_token` matches but `free_total_tokens` does not. */
    public const string SECRET_KEY_PATTERN = '/(?:^|[._])(?:api_?key|password|token|secret|webhook_url|passkey|client_secret)(?:$|[._])/i';

    public const string MASKED = '[masked]';

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function between(array $before, array $after): array
    {
        $flatBefore = self::flatten($before);
        $flatAfter = self::flatten($after);
        $changes = [];

        foreach (array_keys($flatBefore + $flatAfter) as $field) {
            $from = $flatBefore[$field] ?? null;
            $to = $flatAfter[$field] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[(string) $field] = ['from' => $from, 'to' => $to];
        }

        ksort($changes);

        return $changes;
    }

    /**
     * A model's persisted attributes as the audit sees them: casts applied,
     * hidden attributes and bookkeeping columns left out.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model): array
    {
        return Arr::except($model->attributesToArray(), ['id', 'created_at', 'updated_at']);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @param  list<string>  $secretFields  extra dot paths masked for this subject (a path also covers everything beneath it)
     * @return array<string, array{from: mixed, to: mixed}|array{changed: true}>
     */
    public static function mask(array $changes, array $secretFields = []): array
    {
        $masked = [];

        foreach ($changes as $field => $change) {
            if (self::isSecretField($field, $secretFields) || self::carriesCredentials($change['from']) || self::carriesCredentials($change['to'])) {
                $masked[$field] = ['changed' => true];

                continue;
            }

            $masked[$field] = ['from' => self::redact($change['from']), 'to' => self::redact($change['to'])];
        }

        return $masked;
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function scrub(array $context): array
    {
        $scrubbed = self::redact($context);

        return is_array($scrubbed) ? $scrubbed : [];
    }

    /**
     * @param  list<string>  $secretFields
     */
    public static function isSecretField(string $field, array $secretFields = []): bool
    {
        if (preg_match(self::SECRET_KEY_PATTERN, $field) === 1) {
            return true;
        }

        return array_any($secretFields, fn (string $secretField): bool => $field === $secretField || str_starts_with($field, $secretField.'.'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : sprintf('%s.%s', $prefix, $key);

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    private static function carriesCredentials(mixed $value): bool
    {
        if (is_array($value)) {
            return array_any($value, fn ($item): bool => self::carriesCredentials($item));
        }

        return is_string($value) && preg_match('#[a-z][a-z0-9+.\-]*://[^/\s@]+@#i', $value) === 1;
    }

    private static function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::carriesCredentials($value) ? self::MASKED : UrlQueryRedactor::redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[$key] = is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1
                ? self::MASKED
                : self::redact($item);
        }

        return $redacted;
    }
}
