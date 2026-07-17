<?php

namespace Z77\Core\Services;

use Z77\Core\Exception\LayoutManagerException;

/**
 * HtmlView
 *
 * Immutable snapshot of layout state. Renders the full HTML document.
 *
 * Constructed by LayoutManager::buildView() — receives plain data, holds no
 * reference back to the manager. Once built, the manager may be discarded.
 */
class HtmlView
{
    private array $context = [];
    private TemplateRenderer $renderer;

    public function __construct(
        private string $skeletonPath,
        private array  $partials,
        private array  $css,
        private array  $js,
        private string $nameSpace,
    ) {
        $this->renderer = new TemplateRenderer($this->nameSpace);
    }

    public function assign(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Renders the full HTML document.
     *
     * Partials are grouped by level (head, body) and section.
     *
     * Each body section becomes a template variable named after the section key.
     * Examples:
     *   body.header   → $header
     *   body.main     → $main
     *   body.footer   → $footer
     *   body.sidebar  → $sidebar  (only if defined in config or added via Action)
     *
     * Reserved layout variables — always available, take precedence over section names:
     *   $head      — concatenated head partials (meta, seo, favicon, ...)
     *   $css       — <link> tags for all CSS assets
     *   $jsHead    — <script> tags for JS assets with position 'head'
     *   $jsFooter  — <script> tags for JS assets with position 'footer'
     *
     * Body section keys must NOT be: head, css, jsHead, jsFooter.
     */
    public function render(): string
    {
        $renderedByLevel = $this->renderPartials();

        if (!is_file($this->skeletonPath)) {
            throw new LayoutManagerException("Skeleton template not found: {$this->skeletonPath}");
        }

        $bodySections = $renderedByLevel['body'] ?? [];

        // Reserved layout vars override any colliding body section name.
        $layoutVars = array_merge($bodySections, [
            'head'     => implode("\n", $renderedByLevel['head'] ?? []),
            'css'      => $this->renderCss(),
            'jsHead'   => $this->renderClientI18n() . $this->renderJs('head'),
            'jsFooter' => $this->renderJs('footer'),
        ]);

        // Skeleton gets layout vars + context — layout vars take precedence.
        return $this->renderer->render(
            $this->skeletonPath,
            array_merge($this->context, $layoutVars)
        );
    }

    private function renderPartials(): array
    {
        $renderedByLevel = [];
        $labels          = PartialLabels::active();

        foreach ($this->partials as $level => $sections) {
            foreach ($sections as $section => $paths) {
                $html = '';
                foreach ((array) $paths as $path) {
                    $rendered = $this->renderer->render($path, $this->context);
                    // Dev tool: body-level partials get overlay markers (head
                    // partials render meta/link tags — nothing to label).
                    $html .= $labels && $level === 'body'
                        ? PartialLabels::wrap(PartialLabels::nameFromPath($path), $rendered)
                        : $rendered;
                }
                $renderedByLevel[$level][$section] = $html;
            }
        }

        return $renderedByLevel;
    }

    private function renderCss(): string
    {
        $html = '';
        foreach ($this->css as $css) {
            $path  = htmlspecialchars($css['path'] ?? '');
            $media = htmlspecialchars($css['mediaQueryOption'] ?? '');
            $html .= '<link rel="stylesheet" href="' . $path . '"'
                   . ($media !== '' ? ' media="' . $media . '"' : '')
                   . '>' . PHP_EOL;
        }
        return $html;
    }

    /**
     * Renders the client-facing i18n dictionary (context key `clientI18n`) as a
     * JSON data island in <head>, so the shared `core.js` can translate strings
     * it builds at runtime (e.g. the close-button label on dynamically created
     * flash/message elements). Framework chrome — emitted on every full page
     * like the asset tags; empty when no client strings are present.
     *
     * JSON_HEX_TAG prevents a `</script>` breakout from a translated value.
     */
    private function renderClientI18n(): string
    {
        $dict = $this->context['clientI18n'] ?? [];
        if (!is_array($dict) || $dict === []) {
            return '';
        }
        $json = json_encode(
            $dict,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return '<script type="application/json" data-z77-i18n>' . $json . '</script>' . PHP_EOL;
    }

    private function renderJs(string $position): string
    {
        $html = '';
        foreach ($this->js as $js) {
            if (($js['position'] ?? 'footer') !== $position) {
                continue;
            }
            $src  = htmlspecialchars($js['path']);
            $attr = ($js['defer'] ?? false) ? ' defer' : '';
            $attr .= ($js['async'] ?? false) ? ' async' : '';
            $html .= '<script src="' . $src . '"' . $attr . '></script>' . PHP_EOL;
        }
        return $html;
    }
}
