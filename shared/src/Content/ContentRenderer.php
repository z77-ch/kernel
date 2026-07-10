<?php

namespace Z77\Shared\Content;

use Z77\Shared\Entities\Content;

/**
 * Renders a Content document (or a raw block array) to HTML by dispatching each
 * block through the BlockRegistry. Exposed to templates so a frontend controller
 * can do `$contentRenderer->render($content)`.
 */
final class ContentRenderer
{
    public function __construct(private BlockRegistry $registry) {}

    public function render(Content $content): string
    {
        return $this->renderBlocks($content->getBlocks());
    }

    /**
     * @param array<int, mixed> $blocks
     */
    public function renderBlocks(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $html .= $this->registry->render($block);
            }
        }

        return $html;
    }
}
