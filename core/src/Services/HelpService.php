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
 * mode alike: no help route, no second request. Where help is attached a «Hilfe» trigger
 * appears (the window's title bar via core.js; on a page «? Hilfe» in the shell's top bar,
 * `partials/helpTrigger`, HELP-002); a click opens the help window (core.js
 * `_Z77.core.help`). Nothing attached → nothing rendered.
 *
 * A LIST screen attaches with `toolbar: true` (owner 2026-10-20, HELP-003): the trigger then
 * stands at the right end of the list's toolbar (backend shell, slot hc2) instead of the top
 * bar — one help button per page, beside the tabs and tools it explains. The base controller
 * hands the choice to the view as `helpInToolbar`.
 *
 * One help per answer: a second attach replaces the first — the controller decides.
 */
class HelpService
{
    private ?array $attached = null;

    public function attach(string $path, string $nameSpace, array $context = [], string $title = '', bool $toolbar = false): void
    {
        $this->attached = ['path' => $path, 'nameSpace' => $nameSpace, 'context' => $context, 'title' => $title, 'toolbar' => $toolbar];
    }

    public function has(): bool
    {
        return $this->attached !== null;
    }

    /** Whether the trigger stands in the list's toolbar instead of the top bar (HELP-003). */
    public function inToolbar(): bool
    {
        return $this->attached['toolbar'] ?? false;
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
