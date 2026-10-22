<?php
/**
 * Brand mark — the installation's logo, linked to the site root.
 *
 * SHARED markup, per-project content. The framework default is the SITE NAME as a
 * wordmark (owner 2026-10-22 — it used to be a fixed «z77», which every customer site
 * showed): the frontend's `site.name` (frontendConfig, the og:site_name of SiteIdentity)
 * when set, else the installation name (`config/client/systemConfig` → `installationName`),
 * else the host of the canonical base URL. A project with a real logo replaces the WHOLE
 * partial at `override/z77/shared/res/view/templates/partials/brandMark.tpl.php`
 * (the FileFinder override tier of the `Z77\Shared` namespace wins over vendor)
 * and writes whatever its brand is — another wordmark, an <img>, an inline SVG.
 * Nothing else in the framework has to change: every place that shows the mark
 * renders THIS partial (frontend head and foot, backend login, setup, member).
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
 * @var string $label  optional: the text to show instead of the site name
 */
use Z77\Core\DI;

$class = $class ?? '';
$href  = $href  ?? '/';
$label = trim((string) ($label ?? ''));
if ($label === '') {
    $site  = DI::getModuleManager()->getModuleConfig('frontend')?->get('site', []);
    $label = trim((string) (is_array($site) ? ($site['name'] ?? '') : ''));
}
if ($label === '') {
    $label = trim((string) DI::getConfigManager()
        ->getBaseConfig(configName: 'config/systemConfig', throwError: false, cachePersist: false)
        ->getInstallationName(''));
}
if ($label === '' && defined('CANONICAL_BASE_URL')) {
    $label = (string) preg_replace('#^https?://#', '', rtrim((string) CANONICAL_BASE_URL, '/'));
}
?>
<?php if ($href === ''): ?>
<span class="<?= e($class) ?>"><?= e($label) ?></span>
<?php else: ?>
<a class="<?= e($class) ?>" href="<?= e($href) ?>" title="Zur Startseite"><?= e($label) ?></a>
<?php endif; ?>
