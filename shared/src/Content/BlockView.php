<?php

namespace Z77\Shared\Content;

/**
 * Read-only, generic view over ONE content block — the bespoke-template access
 * path: the designer owns the markup and reads block data with get/text/html/list.
 * Independent of the stream path (ContentRenderer), which stays unchanged.
 *
 * A missing block is represented by an empty null-object ({@see empty()},
 * `exists() === false`): field access returns '' and lists return [], so a
 * template needs no null checks — only `Content::has()` to gate the wrapper.
 *
 * Security: `text()` escapes, `html()` runs the InlineMarkdown whitelist. `get()`
 * returns the raw value (for logic / attributes) and MUST be escaped in the
 * template (`e()`), exactly like any other entity field.
 *
 * Schema-aware (ADR-044): a view built with its type's field descriptors (by
 * {@see ContentView}) formats each field only as far as the field's `inline`
 * profile allows — a field without one renders as plain text. A view without a
 * schema keeps the legacy formatting (bold, italic, links on every field).
 */
final class BlockView
{
    private InlineMarkdown $inline;

    /**
     * @param list<array<string, mixed>>|null $schema  field descriptors; null = legacy formatting
     * @param array<string, array{href?:string, attributes?:array<string,string>}> $actions
     *        project actions for `action:` links (schema-aware views only)
     * @param (\Closure(string):string)|null $localizer  page-link localiser for `localize` fields
     */
    public function __construct(
        private array $data = [],
        private bool $exists = true,
        private ?array $schema = null,
        private array $actions = [],
        private ?\Closure $localizer = null
    ) {
        $this->inline = new InlineMarkdown();
    }

    /** Null-object for a missing block. */
    public static function empty(): self
    {
        return new self([], false);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function type(): string
    {
        return (string) ($this->data['type'] ?? '');
    }

    /** Raw field value (logic / attributes) — escape in the template. */
    public function get(string $key, mixed $default = ''): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Escaped plain text. */
    public function text(string $key): string
    {
        return htmlspecialchars((string) ($this->data[$key] ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Inline-formatted safe HTML. Legacy view: `**bold**`, `*italic*`, `[label](url)`.
     * Schema-aware view: only what the field's profile allows.
     */
    public function html(string $key): string
    {
        return $this->inline->toHtml((string) ($this->data[$key] ?? ''), $this->profile($key));
    }

    /**
     * A scalar list (e.g. paragraphs, bullet items) as formatted HTML strings, one
     * per item, under the list field's profile. Object items are skipped — read
     * those with list().
     *
     * @return list<string>
     */
    public function listHtml(string $key): array
    {
        $items   = $this->data[$key] ?? [];
        $profile = $this->profile($key);
        $out     = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_scalar($item)) {
                $out[] = $this->inline->toHtml((string) $item, $profile);
            }
        }
        return $out;
    }

    /**
     * Repeater access. Object items (associative arrays) are wrapped as BlockViews
     * so the template reads them with the same get/text/html API; scalar items are
     * returned raw (escape in the template). Non-array fields yield [].
     *
     * @return array<int, BlockView|scalar>
     */
    public function list(string $key): array
    {
        $items = $this->data[$key] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $item   = $this->descriptor($key)['item'] ?? null;
        $schema = $this->schema !== null ? (is_array($item) ? $item : []) : null;

        $out = [];
        foreach ($items as $row) {
            $out[] = is_array($row) ? new self($row, true, $schema, $this->actions, $this->localizer) : $row;
        }
        return $out;
    }

    /** @return array<string, mixed> the descriptor of $key, [] when unknown or no schema */
    private function descriptor(string $key): array
    {
        foreach ($this->schema ?? [] as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }
        return [];
    }

    /** null = legacy formatting (no schema); otherwise the field's profile (plain if none). */
    private function profile(string $key): ?InlineProfile
    {
        if ($this->schema === null) {
            return null;
        }
        return InlineProfile::fromDescriptor($this->descriptor($key), $this->actions, $this->localizer);
    }
}
