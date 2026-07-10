<?php

namespace Z77\Shared\Content;

/**
 * Renders one block type to safe HTML.
 *
 * The block tag is server-controlled (chosen by the renderer, from a fixed set);
 * all author-supplied text is escaped (and inline-formatted via InlineMarkdown).
 * A project extends the content system by registering further BlockRenderers.
 */
interface BlockRenderer
{
    /** The block `type` this renderer handles (e.g. 'heading', 'text'). */
    public function type(): string;

    /**
     * The authoring shape of this block type — field descriptors the backend
     * block editor turns into one form input each. Co-located with render() so a
     * type's fields and how they render never drift (one class owns both).
     *
     * Each descriptor is an array:
     *   - key     (string)        the block field this input reads/writes
     *   - kind    (string)        one of: text | textarea | select | url | bool | list
     *   - label   (string)        field label (German)
     *   - options (array)         value=>label map — REQUIRED for kind 'select'
     *   - item    (string|array)  REQUIRED for kind 'list':
     *                               a kind string (e.g. 'text') → list of scalars, OR
     *                               a list of descriptors → list of objects (repeater)
     *   - default (mixed)         optional initial value for a fresh block
     *
     * @return list<array<string, mixed>>
     */
    public function schema(): array;

    /**
     * Renderers are stateless (no constructor) so modules can declare them by
     * class name in their config; `InlineMarkdown` is passed in per render call.
     *
     * @param array<string, mixed> $block the raw block data ({type, ...fields})
     * @return string safe HTML
     */
    public function render(array $block, InlineMarkdown $inline): string;
}
