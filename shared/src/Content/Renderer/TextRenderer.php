<?php

namespace Z77\Shared\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * { "type": "text", "content": "..." } → <p>...</p>
 * One paragraph per block; multiple paragraphs = multiple text blocks.
 */
final class TextRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'text';
    }

    public function schema(): array
    {
        return [
            ['key' => 'content', 'kind' => 'textarea', 'label' => 'Text'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $content = (string)($block['content'] ?? '');
        if ($content === '') {
            return '';
        }

        return '<p>'.$inline->toHtml($content).'</p>';
    }
}
