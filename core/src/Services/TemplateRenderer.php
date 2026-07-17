<?php

namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Exception\ViewException
;

/**
 * Renders PHP templates with a context array. Templates can include other
 * templates via $this->partial(...).
 *
 * The renderer instance is exposed as $this inside templates because require
 * runs in the calling method's scope. This enables a fluent partial API
 * without globals or service-locator calls in templates.
 */
class TemplateRenderer
{
    public function __construct(
        private string $defaultNameSpace = ''
    ) {}

    /**
     * Renders a template file with the given context variables.
     *
     * @param string $path    Absolute path to the .tpl.php file
     * @param array  $context Variables made available inside the template
     * @return string         Rendered HTML string
     * @throws ViewException  If the template file does not exist
     */
    public function render(string $path, array $context = []): string
    {
        if (!is_file($path)) {
            throw new ViewException("Template not found: {$path}");
        }

        extract($context, EXTR_SKIP);
        ob_start();
        require $path;
        return ob_get_clean();
    }

    /**
     * Includes another template by FileFinder-resolved path. Used inside templates:
     *   <?= $this->partial('partials/userCard', ['user' => $user]) ?>
     *
     * The path is FileFinder-syntax (no .tpl.php extension). Default namespace is
     * the module the renderer was instantiated for. Pass a different namespace
     * to render a partial from another module.
     *
     * Context is NOT inherited from the parent template — pass explicitly what
     * the partial needs. Keeps partials isolated and reusable.
     */
    public function partial(string $path, array $context = [], ?string $nameSpace = null): string
    {
        $ns = $nameSpace ?? $this->defaultNameSpace;
        $resolvedPath = DI::getFileFinder()->getFirstTplMatch(
            $path . '.tpl.php',
            $ns
        );
        $html = $this->render($resolvedPath, $context);

        return PartialLabels::active()
            ? PartialLabels::wrap($path, $html)
            : $html;
    }
}
