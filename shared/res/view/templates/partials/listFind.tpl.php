<?php
/**
 * The search form of a standard list (listing.md) — a GET form carrying the
 * state that is not a column (the extras, the sort) in hidden fields; the
 * column inputs in the head belong to it through `form="…"`, so Enter
 * searches on the server. Inside a fetch region the form reloads only the
 * list (`data-fetch-region-form`, core.js); without the script it is a
 * plain page load. A kernel partial, namespace `Z77\Shared`.
 *
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $action                the list URL
 * @var array<string, string> $keep   more hidden fields the page carries (a capture state) — optional
 */
$keep = $keep ?? [];
?>
<form id="<?= e($definition->id) ?>" method="get" action="<?= e($action) ?>" role="search" data-fetch-region-form>
    <?php foreach ($keep + $state->hiddenState() as $name => $value): ?>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
    <?php endforeach; ?>
    <button type="submit" class="be-list__find-submit" tabindex="-1">Suchen</button>
</form>
