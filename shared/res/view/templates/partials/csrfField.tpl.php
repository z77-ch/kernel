<?php
/**
 * The hidden CSRF field of a page-mode form, for forms a partial renders: the token comes from the
 * service, not from what a caller remembered to pass, so a form cannot go out with an empty one.
 * `<?= $this->partial('partials/csrfField', [], 'Z77\Shared') ?>`, also in a project's override.
 * Only in a form for a signed-in user or on a page the page cache never stores: a cached page would
 * hand one visitor's token to everybody (cache.md).
 */
use Z77\Core\DI;
?>
<input type="hidden" name="csrf_token" value="<?= e(DI::getCsrfService()->getToken()) ?>">
