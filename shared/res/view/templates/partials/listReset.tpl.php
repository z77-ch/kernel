<?php
/**
 * «Zurücksetzen» of a standard list (listing.md, owner 2026-10-16): one link BEFORE the list's
 * title that drops every column search AND the sort (`ListState::defaultQuery()`); the extras
 * (a view tab, a group) stay. Shown only when there is something to undo
 * (`ListState::isChanged()`). Inside the list's fetch region the link reloads only the region.
 * A kernel partial, namespace `Z77\Shared`; the classes are module-backend's `_list.scss`.
 *
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $action                the list URL
 * @var array<string, string> $keep   more query keys the link carries — optional
 */
if (!$state->isChanged()) {
    return;
}
$keep = $keep ?? [];
$href = $action . '?' . trim(http_build_query($keep) . '&' . $state->defaultQuery(), '&');
?>
<nav class="be-list__toggles" aria-label="Suche und Sortierung">
    <a data-fetch-region-link href="<?= e(rtrim($href, '?')) ?>" title="Suche und Sortierung zurücksetzen">Zurücksetzen</a>
</nav>
