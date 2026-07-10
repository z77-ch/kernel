<?php

namespace Z77\Shared\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

/**
 * { "type": "image", "src": "/...", "alt": "...", "caption": "..."? }
 * → <img> (wrapped in <figure> when a caption is set).
 * src is scheme-gated (site-relative or http/https); alt/caption are escaped.
 */
final class ImageRenderer implements BlockRenderer
{
    public function type(): string
    {
        return 'image';
    }

    public function schema(): array
    {
        return [
            ['key' => 'src', 'kind' => 'url', 'label' => 'Bildquelle (URL)'],
            ['key' => 'alt', 'kind' => 'text', 'label' => 'Alt-Text'],
            ['key' => 'caption', 'kind' => 'text', 'label' => 'Bildunterschrift (optional)'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $src = $this->safeSrc((string)($block['src'] ?? ''));
        if ($src === null) {
            return '';
        }

        $alt = htmlspecialchars((string)($block['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
        $img = '<img src="'.$src.'" alt="'.$alt.'">';

        $caption = trim((string)($block['caption'] ?? ''));
        if ($caption !== '') {
            $cap = htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
            return '<figure>'.$img.'<figcaption>'.$cap.'</figcaption></figure>';
        }

        return $img;
    }

    private function safeSrc(string $src): ?string
    {
        $src = trim($src);
        if ($src === '') {
            return null;
        }
        $escaped = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

        if ($src[0] === '/') {
            return $escaped; // site-relative
        }
        $lower = strtolower($src);
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
            return $escaped;
        }

        return null; // reject data:, javascript:, protocol-relative, etc.
    }
}
