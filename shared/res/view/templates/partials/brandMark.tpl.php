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
 * `logo` => true (opt-in, owner 2026-11-20; the backend login screens pass it): the
 * installation's logo as an <img> instead of the wordmark — what the ONE module declared
 * under `brandLogo` answers (module-mandator: the mandator's «Logo (E-Mail)»), inlined as a
 * `data:` URI by `Z77\Shared\Site\BrandLogo` (no route, nothing else reachable). No
 * source, nothing stored, an unreadable or too large file, any error → the wordmark. The
 * caller adds the modifier `<class>--image` (its bundle styles the picture); the label stays
 * the link title. A project override of this partial still wins over all of it.
 *
 * `siteLogo` => true (opt-in, 2026-11-20; module-frontend's default header and footer pass it):
 * the PUBLIC website's logo as an <img> — what the ONE module declared under `siteLogo` answers
 * (module-mandator: the website variant of its logo set-up, a document in the DMS's public area),
 * LINKED by its `/media` address (the browser caches it), a same-origin path only
 * (`Z77\Shared\Site\SiteLogo`). Nothing stored, any error → the wordmark. Same `--image` modifier.
 * `logo` wins when both are passed.
 *
 * @var string $class     block class of the calling area
 * @var string $href      link target, '' = render unlinked
 * @var string $label     optional: the text to show instead of the site name
 * @var bool   $logo      optional: show the installation's logo (inlined) when there is one
 * @var bool   $siteLogo  optional: show the public website's logo (linked) when there is one
 */
use Z77\Core\DI;
use Z77\Shared\Site\BrandLogo;
use Z77\Shared\Site\SiteLogo;

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
$image = !empty($logo) ? BrandLogo::current(DI::getModuleManager()) : null;
$webLogo = $image === null && !empty($siteLogo) ? SiteLogo::current(DI::getModuleManager()) : null;
if (($image !== null || $webLogo !== null) && $class !== '') {
    $class .= ' ' . $class . '--image';
}
// Built here from escaped parts only — the src is a data: URI of base64 or a checked local path, the size ints.
$content = match (true) {
    $image !== null => '<img src="' . e($image->src) . '" width="' . $image->width . '" height="' . $image->height . '" alt="' . e($image->alt !== '' ? $image->alt : $label) . '">',
    $webLogo !== null => '<img src="' . e($webLogo->url) . '"'
        . ($webLogo->width > 0 && $webLogo->height > 0 ? ' width="' . $webLogo->width . '" height="' . $webLogo->height . '"' : '')
        . ' alt="' . e($webLogo->alt !== '' ? $webLogo->alt : $label) . '">',
    default         => e($label),
};
?>
<?php if ($href === ''): ?>
<span class="<?= e($class) ?>"><?= raw($content) ?></span>
<?php else: ?>
<a class="<?= e($class) ?>" href="<?= e($href) ?>" title="Zur Startseite"><?= raw($content) ?></a>
<?php endif; ?>
