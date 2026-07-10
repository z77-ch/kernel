<?php

namespace Z77\Shared\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * { "type": "list", "style": "bullet"|"number", "items": ["...", "..."] }
 * → <ul>/<ol> with inline-formatted <li> entries.
 */
final class ListRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'list';
    }

    public function schema(): array
    {
        return [
            ['key' => 'style', 'kind' => 'select', 'label' => 'Stil',
             'options' => ['bullet' => 'Aufzählung', 'number' => 'Nummeriert'], 'default' => 'bullet'],
            ['key' => 'items', 'kind' => 'list', 'label' => 'Einträge', 'item' => 'text'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $items = $block['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }

        $tag = ($block['style'] ?? 'bullet') === 'number' ? 'ol' : 'ul';

        $li = '';
        foreach ($items as $item) {
            $li .= '<li>'.$inline->toHtml((string)$item).'</li>';
        }

        return "<{$tag}>{$li}</{$tag}>";
    }
}
