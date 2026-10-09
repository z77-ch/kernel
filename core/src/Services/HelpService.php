<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * Help as a framework service (ADR-048): a controller attaches the help that belongs to the
 * answer it is building — any module, backend, member or frontend alike.
 *
 *   $this->help->attach('Backend/JournalController/oneLine.help', self::NS, ['form' => $form], 'Einzelbuchung');
 *
 * The text is a template (`*.help.tpl.php`) in the normal template tree — a project overrides
 * or adds it under `override/`. It is rendered with the controller's variables (live values
 * allowed) and travels INSIDE the answer as `<template data-help>` at the end of `main` (the
 * base controller hands it to the view as `helpBlock`, HtmlView appends it), page and fetch
 * mode alike: no help route, no second request. Where help is attached an «i»
 * appears (window title bar via core.js, the crumb line on a page); a click opens the help
 * window (core.js `_Z77.core.help`). Nothing attached → nothing rendered.
 *
 * One help per answer: a second attach replaces the first — the controller decides.
 */
class HelpService
{
    private ?array $attached = null;

    public function attach(string $path, string $nameSpace, array $context = [], string $title = ''): void
    {
        $this->attached = ['path' => $path, 'nameSpace' => $nameSpace, 'context' => $context, 'title' => $title];
    }

    public function has(): bool
    {
        return $this->attached !== null;
    }

    public function title(): string
    {
        return $this->attached['title'] ?? '';
    }

    /** The attached help as its transport block; '' without help. A missing template is the developer's error and throws. */
    public function render(): string
    {
        if ($this->attached === null) {
            return '';
        }
        $file = DI::getFileFinder()->getFirstTplMatch($this->attached['path'] . '.tpl.php', $this->attached['nameSpace']);
        $html = (new TemplateRenderer($this->attached['nameSpace']))->render($file, $this->attached['context']);

        return '<template data-help data-help-title="' . htmlspecialchars($this->attached['title'], ENT_QUOTES) . '">'
            . $html . '</template>';
    }
}
