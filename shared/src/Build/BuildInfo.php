<?php

namespace Z77\Shared\Build;

/**
 * Where the installed `vendor/` came from — the one answer to "which framework
 * state is running here?".
 *
 * A deploy build stamps `vendor/z77/build.json` with the commit, branch and
 * dirty flag of every source tree it copied (see `tools/build-stamp.php`; each
 * project's `vendor-deploy.bat` calls it). A DEVELOPMENT checkout carries no
 * stamp: there the junctions point at a working tree that can differ between
 * two requests, so there is nothing true to state — `current()` returns null
 * and callers say "Entwicklung" instead of a stale deploy date. That is why
 * `vendor-dev.bat` deletes the file.
 *
 * The stamp lives INSIDE `vendor/` on purpose. `vendor/` is uploaded as a
 * whole, so the statement can never travel separately from the thing it
 * describes — an override upload alone leaves it untouched, correctly.
 *
 * The dirty flag is not decoration: a deploy build copies a WORKING TREE, not
 * a commit. Without it the panel would name a commit that was never what
 * shipped. `label()` appends `+` for that case.
 *
 * This is read on every backend page. Nothing here throws — a missing,
 * truncated or hand-mangled file reads as "no stamp".
 */
final class BuildInfo
{
    /** Relative to ABS_BASE_PATH. */
    public const FILE = 'vendor/z77/build.json';

    /** Source trees a stamp can describe; a project may stamp a subset. */
    public const FRAMEWORK = 'framework';
    public const PROPBASE  = 'propbase';

    private static bool $looked  = false;
    private static ?self $cached = null;

    /** @param array<string, array<string, mixed>> $sources */
    private function __construct(
        private readonly ?int $builtAt,
        private readonly array $sources,
    ) {
    }

    /** The stamp of this installation, or null on a development checkout. */
    public static function current(): ?self
    {
        if (self::$looked) {
            return self::$cached;
        }
        self::$looked = true;

        if (!defined('ABS_BASE_PATH')) {
            return self::$cached = null;
        }

        return self::$cached = self::read(ABS_BASE_PATH . '/' . self::FILE);
    }

    /** Read a stamp from an explicit path — the seam the test drives. */
    public static function read(string $file): ?self
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        // A hand-edited file may carry a UTF-8 BOM; json_decode() would refuse it.
        $data = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
        if (!is_array($data)) {
            return null;
        }

        $sources = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_array($value) && array_key_exists('commit', $value)) {
                $sources[$key] = $value;
            }
        }
        if ($sources === []) {
            return null;
        }

        $builtAt = isset($data['built_at']) && is_int($data['built_at']) ? $data['built_at'] : null;

        return new self($builtAt, $sources);
    }

    /** Drop the memoised stamp — tests only. */
    public static function forget(): void
    {
        self::$looked = false;
        self::$cached = null;
    }

    /** When this vendor/ was built (unix time), or null. */
    public function builtAt(): ?int
    {
        return $this->builtAt;
    }

    /** @return list<string> the source trees this stamp describes */
    public function sources(): array
    {
        return array_keys($this->sources);
    }

    public function has(string $source): bool
    {
        return isset($this->sources[$source]);
    }

    /** Full commit hash, or null when the build could not ask git. */
    public function commit(string $source): ?string
    {
        $value = $this->sources[$source]['commit'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function branch(string $source): ?string
    {
        $value = $this->sources[$source]['branch'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** True when the copied working tree carried uncommitted changes. */
    public function isDirty(string $source): bool
    {
        return ($this->sources[$source]['dirty'] ?? false) === true;
    }

    /** When the named commit was made (unix time), or null. */
    public function committedAt(string $source): ?int
    {
        $value = $this->sources[$source]['committed_at'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Display form: `8b1b1c6`, or `8b1b1c6+` when the tree was dirty, or
     * `unbekannt` when git could not be asked. Never an empty string — an
     * empty line would read as "nothing to report" instead of "we don't know".
     */
    public function label(string $source): string
    {
        $commit = $this->commit($source);
        if ($commit === null) {
            return 'unbekannt';
        }

        return substr($commit, 0, 7) . ($this->isDirty($source) ? '+' : '');
    }

    /** `committedAt()` formatted, falling back to the build time. */
    public function date(string $source, string $format = 'd.m.Y'): string
    {
        $time = $this->committedAt($source) ?? $this->builtAt;

        return $time === null ? '' : date($format, $time);
    }
}
