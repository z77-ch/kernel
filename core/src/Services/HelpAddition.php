<?php

namespace Z77\Core\Services;

/**
 * The installation's own text to a help (owner 2026-11-15, «Hilfe ergänzen»): an admin adds
 * what the shipped help does not say — a house rule, a name used in this office. The addition
 * is shown below the help in the help window and feeds the KI-Assistent's knowledge
 * ({@see \Z77\Core\Kibot\HelpKnowledge}).
 *
 *   data/help/<topic-id>.txt     plain text, UTF-8, one file per help
 *
 * DATA, never code: the help templates (`*.help.tpl.php`) live in the packages (vendor in a
 * project) — writing one would be code injection, and the next deploy would overwrite it. So
 * the addition is plain text under the project's `data/` (shared across releases), keyed by the
 * help's topic id ({@see HelpService::topicIdFor()} — the id the help block already carries as
 * `data-help-topic`). The id is a slug (`[a-z0-9-]`, ≤ 64), so it can never name a path outside
 * the directory; whether a help with that id EXISTS is the caller's check (the backend's
 * `HelpController` asks the template discovery).
 *
 * The text: blank line = new paragraph, line breaks inside a paragraph kept. No markup — the
 * output escapes everything (`Z77\Shared` partial `partials/helpAddition`, `e()`). At most {@see MAX_CHARS}.
 * An empty addition removes the file.
 */
final class HelpAddition
{
    /** Directory below the project root. */
    public const DIR = 'data/help';

    /** Characters (not bytes) one addition may have. */
    public const MAX_CHARS = 20000;

    private const ID = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dir), '/');
    }

    /** The installation's additions: `<ABS_BASE_PATH>/data/help`. */
    public static function forInstallation(): self
    {
        return new self(ABS_BASE_PATH . '/' . self::DIR);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Whether `$id` has the shape of a help topic id — the only ids a file name is built from. */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    /**
     * The file of a help's addition (it need not exist).
     *
     * @throws \InvalidArgumentException for an id that is not a topic id
     */
    public function file(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException('help addition: invalid help id');
        }
        return $this->dir . '/' . $id . '.txt';
    }

    /** The addition of a help; '' when there is none (or the id is not a topic id). */
    public function load(string $id): string
    {
        if (!self::isValidId($id)) {
            return '';
        }
        $file = $this->file($id);
        if (!is_file($file)) {
            return '';
        }
        $text = (string) file_get_contents($file);
        return mb_check_encoding($text, 'UTF-8') ? self::normalize($text) : '';
    }

    /**
     * Writes the addition of a help — normalized; an empty text removes it. Write-then-rename:
     * a reader (the help, the assistant's index) never sees half a file.
     *
     * @throws \InvalidArgumentException invalid id, not UTF-8, or longer than {@see MAX_CHARS}
     * @throws \RuntimeException when the file cannot be written
     */
    public function save(string $id, string $text): void
    {
        $file = $this->file($id);
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('help addition: the text is not UTF-8');
        }
        $text = self::normalize($text);
        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new \InvalidArgumentException('help addition: longer than ' . self::MAX_CHARS . ' characters');
        }
        if ($text === '') {
            if (is_file($file) && !@unlink($file)) {
                throw new \RuntimeException('help addition: cannot remove ' . $file);
            }
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('help addition: cannot create ' . $this->dir);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $text . "\n") === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('help addition: cannot write ' . $file);
        }
    }

    /**
     * One form for storage, display and the index: line ends `\n`, control characters (other
     * than line break and tab) gone, no trailing blanks, at most one blank line between
     * paragraphs, nothing around the whole.
     */
    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        return trim($text);
    }

    /** @return list<string> the paragraphs of a normalized text (blank line = new paragraph) */
    public static function paragraphs(string $text): array
    {
        $text = self::normalize($text);
        return $text === '' ? [] : explode("\n\n", $text);
    }
}
