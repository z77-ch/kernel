<?php
/**
 * Brand mark — the installation's logo, linked to the site root.
 *
 * SHARED markup, per-project content. The framework default is the `z77`
 * wordmark; a project replaces the WHOLE partial at
 * `override/z77/shared/res/view/templates/partials/brandMark.tpl.php`
 * (the FileFinder override tier of the `Z77\Shared` namespace wins over vendor)
 * and writes whatever its brand is — another wordmark, an <img>, an inline SVG.
 * Nothing else in the framework has to change: every place that shows the mark
 * renders THIS partial.
 *
 * It carries NO styling of its own. The caller passes the block class its own
 * bundle already styles (`login__logo` in the backend, `me-brand` in member) —
 * the mark fails ADR-018's geometry-only test (colour, font and radius ARE the
 * component), so its CSS stays per view-area and only the markup is shared.
 *
 * `href` defaults to the site root. Pass an empty string where that link would
 * lead nowhere yet (setup) — then the mark renders as a plain <span>.
 *
 * @var string $class  block class of the calling area
 * @var string $href   link target, '' = render unlinked
 */
$class = $class ?? '';
$href  = $href  ?? '/';
?>
<?php if ($href === ''): ?>
<span class="<?= e($class) ?>">z77</span>
<?php else: ?>
<a class="<?= e($class) ?>" href="<?= e($href) ?>" title="Zur Startseite">z77</a>
<?php endif; ?>
