<?php

namespace Z77\Shared\Content;

use Z77\Shared\Content\Renderer\HeadingRenderer;
use Z77\Shared\Content\Renderer\TextRenderer;
use Z77\Shared\Content\Renderer\ListRenderer;
use Z77\Shared\Content\Renderer\ImageRenderer;

/**
 * Builds a BlockRegistry seeded with the framework's built-in block types.
 * Wired into DI once; a project can register additional renderers on top.
 */
final class DefaultBlockRegistry
{
    private function __construct() {}

    public static function create(): BlockRegistry
    {
        $registry = new BlockRegistry(new InlineMarkdown());

        $registry->register(new HeadingRenderer());
        $registry->register(new TextRenderer());
        $registry->register(new ListRenderer());
        $registry->register(new ImageRenderer());

        return $registry;
    }
}
