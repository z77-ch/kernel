/**
 * Partial-label overlay (framework dev tool — see PartialLabels.php).
 *
 * Discovers the `<!--z77p:NAME-->…<!--/z77p-->` comment markers the renderer
 * emits around every partial, computes each partial's bounding rect via a DOM
 * Range between its markers, and floats a semi-transparent label over the
 * top-left corner. Nested partials indent by nesting depth so tags stay
 * readable. Zero layout impact: labels live in a zero-size absolute layer on
 * <body> with pointer-events:none.
 *
 * Only ever served when the tool is active (flag + DEBUG + admin) — never in
 * production output. Positioning/depth/observer logic ported from the proven
 * zihlundsee prototype (work/docs/topics/partial-labels.md appendix).
 */
(function () {
    'use strict';

    var layer = null;

    /** Pairs opening/closing markers document-wide; depth = nesting level. */
    function collect() {
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT, null);
        var stack = [], found = [], node;

        while ((node = walker.nextNode())) {
            var text = node.nodeValue;
            if (text.indexOf('z77p:') === 0) {
                stack.push({ name: text.slice(5), start: node, depth: stack.length });
            } else if (text === '/z77p') {
                var open = stack.pop();
                if (open) {
                    found.push({ name: open.name, start: open.start, end: node, depth: open.depth });
                }
            }
        }
        return found;
    }

    function render() {
        if (layer) { layer.remove(); }
        layer = document.createElement('div');
        layer.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;overflow:visible;z-index:99999;pointer-events:none;';

        collect().forEach(function (entry) {
            var range = document.createRange();
            range.setStartAfter(entry.start);
            range.setEndBefore(entry.end);
            var rect = range.getBoundingClientRect();
            if (rect.width === 0 && rect.height === 0) {
                return; // hidden (e.g. a closed modal shell)
            }
            var tag = document.createElement('div');
            tag.textContent = entry.name;
            tag.style.cssText =
                'position:absolute;' +
                'top:' + Math.round(rect.top + window.scrollY + entry.depth * 20) + 'px;' +
                'left:' + Math.round(rect.left + window.scrollX + entry.depth * 20) + 'px;' +
                'background:rgba(20,20,20,.55);color:#fff;' +
                'font:11px/1.7 Consolas,monospace;padding:1px 7px;border-radius:3px;' +
                'white-space:nowrap;';
            layer.appendChild(tag);
        });
        document.body.appendChild(layer);
    }

    document.addEventListener('DOMContentLoaded', function () {
        render();

        var pending = null;
        function schedule() {
            if (pending !== null) { return; }
            pending = window.setTimeout(function () { pending = null; render(); }, 200);
        }
        window.addEventListener('resize', schedule);
        if (window.ResizeObserver) {
            // Repositions after late layout changes (loading images/Lotties).
            new ResizeObserver(schedule).observe(document.body);
        }
    });
}());
