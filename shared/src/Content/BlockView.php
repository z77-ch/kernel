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
 */
final class BlockView
{
    private InlineMarkdown $inline;

    public function __construct(
        private array $data = [],
        private bool $exists = true
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

    /** Inline-formatted safe HTML (`**bold**`, `*italic*`, `[label](url)`). */
    public function html(string $key): string
    {
        return $this->inline->toHtml((string) ($this->data[$key] ?? ''));
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

        $out = [];
        foreach ($items as $item) {
            $out[] = is_array($item) ? new self($item) : $item;
        }
        return $out;
    }
}
