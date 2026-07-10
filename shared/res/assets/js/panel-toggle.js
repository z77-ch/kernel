var _Z77 = _Z77 || {};
_Z77.ui = _Z77.ui || {};

/**
 * Generic, data-attribute driven panels + collapsibles. No per-component JS.
 *
 * Dropdown panel (click toggles, outside-click / Esc closes, link navigates):
 *   <div data-panel-root>
 *     <button data-panel-trigger aria-expanded="false"
 *             data-panel-open-class="my--open">…</button>
 *     <div data-panel hidden>…</div>
 *   </div>
 *
 * Collapsible section (click toggles, scoped, no outside-close):
 *   <button data-collapse-trigger aria-controls="x" aria-expanded="false">…</button>
 *   <div id="x" data-collapse hidden>…</div>
 *
 * Used by the backend topbar (environment switcher + avatar service panel) and
 * the avatar "Info" / "Aussehen" sections; reusable anywhere the contract holds.
 */
_Z77.ui.panels = (function () {
    function _panelFor(trigger) {
        var root = trigger.closest('[data-panel-root]');
        return root ? root.querySelector('[data-panel]') : null;
    }

    function _openClass(trigger) {
        return trigger.getAttribute('data-panel-open-class') || '';
    }

    function _close(trigger, panel) {
        if (!panel || panel.hidden) return;
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        var c = _openClass(trigger);
        if (c) trigger.classList.remove(c);
    }

    function _open(trigger, panel) {
        if (!panel) return;
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        var c = _openClass(trigger);
        if (c) trigger.classList.add(c);
    }

    function _closeAll(except) {
        var triggers = document.querySelectorAll('[data-panel-trigger]');
        for (var i = 0; i < triggers.length; i++) {
            if (triggers[i] === except) continue;
            _close(triggers[i], _panelFor(triggers[i]));
        }
    }

    function init(scope) {
        scope = scope || document;

        var triggers = scope.querySelectorAll('[data-panel-trigger]');
        for (var i = 0; i < triggers.length; i++) {
            (function (trigger) {
                if (trigger._z77PanelBound) return;
                trigger._z77PanelBound = true;
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var panel = _panelFor(trigger);
                    if (!panel) return;
                    var willOpen = panel.hidden;
                    _closeAll(trigger);
                    if (willOpen) _open(trigger, panel);
                    else _close(trigger, panel);
                });
            })(triggers[i]);
        }

        var collapses = scope.querySelectorAll('[data-collapse-trigger]');
        for (var j = 0; j < collapses.length; j++) {
            (function (trigger) {
                if (trigger._z77CollapseBound) return;
                trigger._z77CollapseBound = true;
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var id = trigger.getAttribute('aria-controls');
                    var body = id ? document.getElementById(id) : null;
                    if (!body) return;
                    var open = body.hidden;
                    body.hidden = !open;
                    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            })(collapses[j]);
        }
    }

    // Outside-click + Esc close any open dropdown panel (bound once).
    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        if (e.target.closest('[data-panel-trigger]')) return; // own handler toggles
        var insidePanel = e.target.closest('[data-panel]');
        if (insidePanel) {
            // A link / menu item closes the panel but lets navigation proceed.
            if (e.target.closest('a, [role="menuitem"]')) _closeAll(null);
            return;
        }
        _closeAll(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') _closeAll(null);
    });

    document.addEventListener('DOMContentLoaded', function () { init(document); });

    return { init: init };
})();
