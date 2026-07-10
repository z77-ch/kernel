<?php

namespace Z77\Shared\Content;

use Z77\Core\DI;

/**
 * Maps a block `type` to its BlockRenderer. The single extension point for the
 * content system: a project registers custom block types here.
 *
 * Unknown types render to an empty string (safe by default — never raw output).
 */
final class BlockRegistry
{
    /** @var array<string, BlockRenderer> */
    private array $renderers = [];

    public function __construct(private InlineMarkdown $inline) {}

    /**
     * Assembles the app-wide registry on demand: the core defaults
     * ({@see DefaultBlockRegistry}) plus every module's declared `contentBlocks`
     * (renderer FQCNs). Walks the modules via the DI ModuleManager — but is NOT
     * itself a DI service: a consumer (content renderer / backend editor) calls
     * this when it needs the registry, and holds the result locally. See adr-012.
     */
    public static function assemble(): self
    {
        $registry = DefaultBlockRegistry::create();

        $moduleManager = DI::getModuleManager();
        foreach ($moduleManager->getModuleKeys() as $moduleKey) {
            $config = $moduleManager->getModuleConfig($moduleKey);
            if ($config === null) {
                continue;
            }
            foreach ((array) $config->get('contentBlocks', []) as $rendererClass) {
                $registry->register(new $rendererClass());
            }
        }

        return $registry;
    }

    public function register(BlockRenderer $renderer): void
    {
        $this->renderers[$renderer->type()] = $renderer;
    }

    public function has(string $type): bool
    {
        return isset($this->renderers[$type]);
    }

    /** Registered block type names (used by the backend editor to offer types). */
    public function types(): array
    {
        return array_keys($this->renderers);
    }

    /** Field schema for one type, or null when the type is unknown. */
    public function schema(string $type): ?array
    {
        return isset($this->renderers[$type]) ? $this->renderers[$type]->schema() : null;
    }

    /**
     * Field schemas for every registered type, keyed by type — the backend block
     * editor builds its per-type forms from this.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function schemas(): array
    {
        $out = [];
        foreach ($this->renderers as $type => $renderer) {
            $out[$type] = $renderer->schema();
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function render(array $block): string
    {
        $type = (string)($block['type'] ?? '');
        $renderer = $this->renderers[$type] ?? null;

        return $renderer?->render($block, $this->inline) ?? '';
    }
}
