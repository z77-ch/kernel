<?php
/**
 * Tree picker — a FILTER over a long tree, as a FIELD in the toolbar (first user: the product
 * group of the article list, owner 2026-10-18; css-backend.md «buttons, tabs, switch»). Generic:
 * it knows nodes, not product groups (Rule 9). The choice is a LINK per node, built by the
 * caller (`$href`) — the address carries the filter, the server validates it, the script never
 * decides anything (Rule 7).
 *
 * The field: a muted label, the chosen node's path shortened («Handelswaren › … › SD-Cards»,
 * the full path as `title`), a caret; «×» (a link to `$href(null)`) clears the filter.
 *
 * Inside a fetch region (the article picker's window, 2026-10-19) every choice reloads only that
 * region: «×» and the fallback list's links carry `data-fetch-region-link` (inert outside a
 * region), core.js marks the popover's links the same way. core.js enhances a picker in fetched
 * content through `wire()` (a window, a popup, a swapped region).
 *
 * Without JavaScript the field is the `<summary>` of a `<details>` whose panel lists every node
 * as an indented link («all» first) — it works as it is. core.js «tree picker» enhances it: it
 * reads the JSON data island `script[data-tree-picker-data]` (never HTML strings) and replaces
 * the list by a popover with a search over all levels and ONE level at a time.
 *
 * A kernel partial, namespace `Z77\Shared`; the classes are module-backend's `_tree-picker.scss`.
 *
 * @var string $id         DOM id of the picker (unique on the page)
 * @var string $label      the field's label, e.g. «Warengruppe»
 * @var string $allLabel   the entry for «no filter», e.g. «Alle Warengruppen»
 * @var list<array{id: int|string, parent: int|string|null, name: string, count?: int, note?: string}> $nodes
 *                         every node in TREE ORDER (a parent before its children); `count` = what
 *                         the node holds incl. its sub-nodes; `note` = a muted suffix («inaktiv»)
 * @var int|string|null $value  the chosen node's id, null = no filter
 * @var callable(int|string|null): string $href  the link of a node (null = no filter)
 * @var int|null $allCount     what «all» holds — optional
 * @var array<string, string> $labels  optional overrides of the popover's texts (see $text below)
 */
if (!isset($id, $nodes, $href) || $nodes === []) { return; }
$label    = $label ?? 'Auswahl';
$allLabel = $allLabel ?? 'Alle';
$value    = $value ?? null;
$text     = array_merge([
    'search' => 'Suchen …',
    'top'    => 'Alle',
    'allIn'  => 'Alle in «%s»',
    'open'   => 'Untergruppen öffnen',
    'hits'   => '%d Treffer in allen Stufen',
    'max'    => 'die ersten %d Treffer in allen Stufen',
    'none'   => 'Nichts gefunden.',
    'level'  => '%d Einträge',
    'foot'   => '',
    'clear'  => 'Filter aufheben',
], $labels ?? []);

$byId = [];
foreach ($nodes as $node) {
    $byId[(string) $node['id']] = $node;
}
// Names from the root down to $key (a guard against a broken parent chain).
$chain = static function (string $key) use ($byId): array {
    $names = [];
    for ($node = $byId[$key] ?? null, $guard = 64; $node !== null && $guard-- > 0; $node = $node['parent'] === null ? null : ($byId[(string) $node['parent']] ?? null)) {
        array_unshift($names, (string) $node['name']);
    }
    return $names;
};
$current = $value !== null && isset($byId[(string) $value]) ? (string) $value : null;
$path    = $current !== null ? $chain($current) : [];
$up      = match (true) {
    count($path) > 2  => $path[0] . ' › … › ',
    count($path) === 2 => $path[0] . ' › ',
    default           => '',
};

$island = [
    'value' => $current !== null ? $byId[$current]['id'] : null,
    'all'   => ['label' => $allLabel, 'href' => $href(null), 'count' => $allCount ?? null],
    'nodes' => array_map(static fn(array $n): array => array_filter([
        'id'     => $n['id'],
        'parent' => $n['parent'],
        'name'   => (string) $n['name'],
        'count'  => isset($n['count']) ? (int) $n['count'] : null,
        'note'   => (string) ($n['note'] ?? ''),
        'href'   => $href($n['id']),
    ], static fn($v, $k) => $k === 'parent' || ($v !== null && $v !== ''), ARRAY_FILTER_USE_BOTH), $nodes),
    'text'  => $text,
];
?>
<div class="be-tree-picker" id="<?= e($id) ?>" data-tree-picker>
    <details class="be-tree-picker__details">
        <summary class="be-tree-picker__field"<?= $path !== [] ? ' title="' . e(implode(' › ', $path)) . '"' : '' ?>>
            <span class="be-tree-picker__label"><?= e($label) ?></span>
            <span class="be-tree-picker__value"><?php if ($current === null): ?><?= e($allLabel) ?><?php else: ?><?php if ($up !== ''): ?><span class="be-tree-picker__up"><?= e($up) ?></span><?php endif; ?><?= e(end($path)) ?><?php endif; ?></span>
            <span class="be-tree-picker__caret" aria-hidden="true">▼</span>
        </summary>
        <nav class="be-tree-picker__panel be-tree-picker__fallback" aria-label="<?= e($label) ?>" data-tree-picker-fallback>
            <a class="be-tree-picker__link" href="<?= e($href(null)) ?>" data-fetch-region-link<?= $current === null ? ' aria-current="true"' : '' ?>><?= e($allLabel) ?></a>
            <?php foreach ($nodes as $node): $key = (string) $node['id']; $depth = count($chain($key)) - 1; ?>
            <a class="be-tree-picker__link" href="<?= e($href($node['id'])) ?>" data-fetch-region-link style="--be-tree-picker-depth: <?= $depth ?>"<?= $key === $current ? ' aria-current="true"' : '' ?> title="<?= e(implode(' › ', $chain($key))) ?>"><?= e((string) $node['name']) ?><?php if (($node['note'] ?? '') !== ''): ?> <span class="be-tree-picker__note">(<?= e($node['note']) ?>)</span><?php endif; ?><?php if (isset($node['count'])): ?> <span class="be-tree-picker__count"><?= (int) $node['count'] ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
    </details>
    <?php if ($current !== null): ?>
    <a class="be-tree-picker__clear" href="<?= e($href(null)) ?>" data-fetch-region-link title="<?= e($text['clear']) ?>" aria-label="<?= e($label . ': ' . $text['clear']) ?>">×</a>
    <?php endif; ?>
    <script type="application/json" data-tree-picker-data><?= json_encode($island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</div>
