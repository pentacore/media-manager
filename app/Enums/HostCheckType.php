<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;
use Illuminate\Validation\Rule;

enum HostCheckType: string
{
    use EnumUtils;

    case MountPresent = 'mount_present';
    case PathOnMount = 'path_on_mount';
    case FileFreshness = 'file_freshness';
    case DbPing = 'db_ping';
    case MountpointProtected = 'mountpoint_protected';

    public function label(): string
    {
        return match ($this) {
            self::MountPresent => 'Mount present',
            self::PathOnMount => 'Path on mount',
            self::FileFreshness => 'File freshness',
            self::DbPing => 'Database ping',
            self::MountpointProtected => 'Mountpoint protected',
        };
    }

    /**
     * One line of valid UTF-8: no control character (C0, DEL, C1) and no Unicode line or paragraph separator.
     * The `u` flag also makes invalid UTF-8 fail the match instead of reaching json_encode().
     */
    private const string SINGLE_LINE = '[^\p{Cc}\x{2028}\x{2029}]';

    /**
     * A character of a Go `filepath.Match` glob outside a bracket class, an escaped character, or a closed,
     * non-empty class (`[0-9]`, `[^x]`) whose items are characters or ranges. Rejects what the agent would
     * refuse as a bad pattern on every run: an unclosed `[`, a stray `]`, `[]`, a trailing `\`.
     */
    private const string GLOB = '/\A(?:[^\[\]\\\\\/]|\\\\.|\[\^?(?:(?:[^\[\]\\\\\/-]|\\\\.)(?:-(?:[^\[\]\\\\\/-]|\\\\.))?)+\])+\z/u';

    /**
     * Validation rules for this type's params, keyed by the bare param name.
     * Callers prefix the keys with `params.`. The params go to an agent running as root,
     * so every string is a single line of valid UTF-8.
     * A file_freshness `pattern` is a file-name glob: the agent matches entries directly in `path`, never below it.
     * A db_ping `timeout_seconds` stays within the 1–9 s the agent clamps it to.
     *
     * @return array<string, list<mixed>>
     */
    public function paramRules(): array
    {
        $singleLine = sprintf('regex:/\A%s+\z/u', self::SINGLE_LINE);
        $absolutePath = ['required', 'string', 'max:1024', sprintf('regex:/\A\/%s*\z/u', self::SINGLE_LINE)];

        return match ($this) {
            self::MountPresent => [
                'path' => $absolutePath,
                'fstype' => ['nullable', 'string', 'max:32', 'regex:/\A[a-z0-9._-]+\z/'],
                'source' => ['nullable', 'string', 'max:1024', $singleLine],
            ],
            self::PathOnMount => [
                'path' => $absolutePath,
                'mount' => $absolutePath,
            ],
            self::FileFreshness => [
                'path' => $absolutePath,
                'pattern' => ['nullable', 'string', 'max:255', $singleLine, sprintf('regex:%s', self::GLOB)],
                'warn_hours' => ['required', 'numeric', 'min:0.1', 'max:8760'],
                'crit_hours' => ['required', 'numeric', 'min:0.1', 'max:8760', 'gte:params.warn_hours'],
            ],
            self::DbPing => [
                'engine' => ['required', DbPingEngine::validationRule()],
                'host' => ['required', 'string', 'max:253', Rule::anyOf([
                    ['ip'],
                    ['regex:/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\z/'],
                ])],
                'port' => ['required', 'integer', 'between:1,65535'],
                'timeout_seconds' => ['nullable', 'integer', 'between:1,9'],
            ],
            self::MountpointProtected => [
                'path' => $absolutePath,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function paramKeys(): array
    {
        return array_keys($this->paramRules());
    }

    /**
     * What the check looks at: its path, or host:port for a database ping.
     *
     * @param  array<string, mixed>  $params
     */
    public function subject(array $params): string
    {
        return match ($this) {
            self::DbPing => sprintf('%s:%s', $params['host'] ?? '', $params['port'] ?? ''),
            default => (string) ($params['path'] ?? ''),
        };
    }
}
