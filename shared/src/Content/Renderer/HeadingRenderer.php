<?php

namespace Z77\Shared\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * { "type": "heading", "level": 1-6, "text": "..." } → <hN>...</hN>
 * Level is clamped to 1..6 (default 2); text is inline-formatted.
 */
final class HeadingRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'heading';
    }

    public function schema(): array
    {
        return [
            ['key' => 'level', 'kind' => 'select', 'label' => 'Ebene',
             'options' => [1 => 'H1', 2 => 'H2', 3 => 'H3', 4 => 'H4', 5 => 'H5', 6 => 'H6'], 'default' => 2],
            ['key' => 'text', 'kind' => 'text', 'label' => 'Text'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $level = (int)($block['level'] ?? 2);
        $level = max(1, min(6, $level));
        $text  = $inline->toHtml((string)($block['text'] ?? ''));

        return "<h{$level}>{$text}</h{$level}>";
    }
}
