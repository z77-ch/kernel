/* z77 shared client core
 *
 * Module-agnostic. NO module-specific CSS classes or DOM ids.
 * Markup contracts use data-* attributes only:
 *
 *   [data-z77-popup]              <dialog> root for the popup channel
 *   [data-z77-popup-body]         injection target inside the popup
 *   [data-popup-close]            any clickable closes the popup
 *   [data-z77-field-wrapper]      wrapper holding a labelled <input>/<select>
 *   [data-z77-field-error]        error-message slot (auto-created if absent)
 *   [data-fetch-get]              generic GET trigger
 *   [data-fetch-post]             generic POST submit
 *   [data-check-url]              attribute on a form → blur-validates each input
 *   [data-copy="<selector>"]      any clickable → copies the named element's text
 *   [data-select-on-focus]        a readonly field selects its whole text on focus / click
 *   [data-print]                  any clickable → window.print()
 *   select[data-switch]           shows the [data-switch-case] of its value in its [data-switch-scope] (see «small triggers»)
 *   form[data-dirty="clean|dirty"] knows unsaved changes; its [data-dirty-submit] follows (see «dirty forms»)
 *   [data-fetch-get][data-popup-refresh="<region>"]  the popup it opens reloads that fetch region on close (see «popup»)
 *   [data-fill-url="<url>"]       a choice fills fields of its [data-fill-scope] (see «fill»)
 *   [data-rows="<prefix>"]        rows to add, remove, drag and indent — [data-row], [data-row-grip],
 *                                 [data-row-indent], [data-row-no], [data-row-remove],
 *                                 template[data-row-template], [data-row-add] (see «rows»)
 *   textarea[data-autosize]       grows with its text; ="title" shows the first line as a title (see «autosize»)
 *   [data-sum]                    a live total of the lines inside it, display only (see «line sums»)
 *   select[data-rate-schedule-ref] its options' rates follow a date field of the form (see «dated rates»)
 *   [data-price-mode]             its rows convert their prices when the price mode changes (see «price mode»)
 *   [data-tree-picker]            a tree filter as a field: <details> + the JSON island
 *                                 script[data-tree-picker-data] → search + one level at a time (see «tree picker»)
 *   [data-pick-list]              a searching choice list: [data-pick-search] types, ↑/↓ move over
 *                                 [data-pick-item], Enter picks (see «pick list»)
 *   [data-menu-open="<url>"]      a row's ⋮: GET <url>, the answer's items open as a context menu at
 *                                 the trigger; Esc, a click outside or on an item closes it (see «context menu»)
 *   [data-window-open="<url>"]    opens a controller-led window (ADR-047, see «windows»);
 *     [data-window-with="a b"]    the form fields a, b ride along as query parameters
 *   [data-fetch-region="<name>"]  a part of the page that reloads alone —
 *     a[data-fetch-region-link] / form[data-fetch-region-form] inside it (see «fetch regions»)
 *   form[target="_blank"][data-submit-navigate="<url>"]
 *                                 after the native submit into a new tab, THIS tab goes to <url>
 *                                 (see «submit-navigate»)
 *
 * Native semantics carry validity state:
 *   input/select … aria-invalid="true|false"
 *   form / trigger … aria-busy="true" while its request runs (see «busy guard»)
 *
 * Extension points:
 *   _Z77.core.fetch.registerEnvelopeHandler(key, fn)
 *   _Z77.core.fetch.registerCommand(action, fn)
 *
 * Action-scoped scripts (lazy-loaded via the `load-script` command) register
 * their initialiser in:
 *   _Z77.scriptInit['name'] = function (scope) { … }
 */
var _Z77 = _Z77 || {};
_Z77.core = _Z77.core || {};
_Z77.scriptInit = _Z77.scriptInit || {};   // registry: name → fn(scope) — populated by lazy-loaded scripts

/* ── i18n channel ───────────────────────────────────────────────────────────
 * Client-side counterpart to PHP's t(): reads the JSON data island the server
 * inlines in <head> (`<script type="application/json" data-z77-i18n>`) — the
 * current language's JS-facing dictionary subset (js.* keys + shared keys like
 * common.close). Strings stay single-source in data/framework/i18n/{lang}.json;
 * JS only reads them. `load()` runs once at boot, before anything renders.
 * `t(key, fallback)` returns the fallback (then the key) when a string is
 * missing — so the module degrades to the literal instead of breaking.
 */
_Z77.core.i18n = (function () {
    var _dict = {};

    function load() {
        var el = document.querySelector('script[type="application/json"][data-z77-i18n]');
        if (!el) return;
        try { _dict = JSON.parse(el.textContent) || {}; } catch (e) { _dict = {}; }
    }

    function t(key, fallback) {
        var value = _dict[key];
        if (typeof value === 'string') return value;
        return fallback !== undefined ? fallback : key;
    }

    return { load: load, t: t };
})();

/* ── flash channel ──────────────────────────────────────────────────────── */
_Z77.core.flash = (function () {
    var _container;
    var AUTO_DISMISS_MS = 5000;

    function _getContainer() {
        return _container || (_container = document.getElementById('flash-messages'));
    }

    function _remove(el) {
        if (el && el.parentNode) { el.parentNode.removeChild(el); }
    }

    function _wireMsg(msg) {
        var close = msg.querySelector('.flash-msg__close');
        if (close) close.addEventListener('click', function () { _remove(msg); });
        if (msg.classList.contains('flash-msg--success') || msg.classList.contains('flash-msg--info')) {
            setTimeout(function () { _remove(msg); }, AUTO_DISMISS_MS);
        }
    }

    function show(type, text) {
        var container = _getContainer();
        if (!container) return;

        var msg = document.createElement('div');
        msg.className = 'flash-msg flash-msg--' + type;

        var textEl = document.createElement('span');
        textEl.className = 'flash-msg__text';
        textEl.textContent = text;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'flash-msg__close';
        close.setAttribute('aria-label', _Z77.core.i18n.t('common.close', 'Schliessen'));
        close.textContent = '×';

        msg.appendChild(textEl);
        msg.appendChild(close);
        container.appendChild(msg);
        _wireMsg(msg);
    }

    function wireExisting() {
        var container = _getContainer();
        if (!container) return;
        container.querySelectorAll('.flash-msg').forEach(_wireMsg);
    }

    return { show: show, wireExisting: wireExisting };
})();

/* ── message channel ────────────────────────────────────────────────────── */
_Z77.core.message = (function () {
    var _container;

    function _getContainer() {
        return _container || (_container = document.getElementById('messages'));
    }

    function _remove(el) {
        if (el && el.parentNode) { el.parentNode.removeChild(el); }
    }

    function _wireMsg(msg) {
        var close = msg.querySelector('.msg-popup__close');
        if (close) close.addEventListener('click', function () { _remove(msg); });
    }

    function show(type, text) {
        var container = _getContainer();
        if (!container) return;

        var msg = document.createElement('div');
        msg.className = 'msg-popup msg-popup--' + type;

        var textEl = document.createElement('span');
        textEl.className = 'msg-popup__text';
        textEl.textContent = text;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'msg-popup__close';
        close.setAttribute('aria-label', _Z77.core.i18n.t('common.close', 'Schliessen'));
        close.textContent = '×';

        msg.appendChild(textEl);
        msg.appendChild(close);
        container.appendChild(msg);
        _wireMsg(msg);
    }

    function wireExisting() {
        var container = _getContainer();
        if (!container) return;
        container.querySelectorAll('.msg-popup').forEach(_wireMsg);
    }

    return { show: show, wireExisting: wireExisting };
})();

/* ── popup channel ──────────────────────────────────────────────────────── */
_Z77.core.popup = (function () {
    function _root() { return document.querySelector('[data-z77-popup]'); }
    function _body() {
        var root = _root();
        return root ? root.querySelector('[data-z77-popup-body]') : null;
    }

    function show(html, sourceUrl) {
        var root = _root();
        var body = _body();
        if (!root || !body) return;
        body.innerHTML = html;
        _Z77.core.wire(body, sourceUrl);
        _bindCheckUrl(body);
        if (typeof root.showModal === 'function') {
            if (!root.open) root.showModal();
        }
    }

    function close() {
        var root = _root();
        if (!root) return;
        root.removeAttribute('data-fullscreen'); // reset so the next popup opens normal
        if (root.open && typeof root.close === 'function') root.close();
    }

    /* [data-popup-refresh="<region>"] on the trigger that opened the popup: when the popup closes
     * (saved, cancelled, Esc), that fetch region — in the trigger's window, else the page —
     * reloads from its `data-fetch-region-src`, its own fields' values riding along (the user's
     * current choice stays). First user: the order's «Debitor anpassen» → «Zahlungskonditionen»
     * (2026-10-18). The region reloads read-only; nothing is saved by it. */
    var _refreshFrom = null;
    function refreshOnClose(trigger) { _refreshFrom = trigger; }
    function _refreshAfterClose() {
        var trigger = _refreshFrom;
        _refreshFrom = null;
        if (!trigger || !trigger.isConnected) return;
        var name = trigger.getAttribute('data-popup-refresh');
        var scope = _Z77.core.windows.of(trigger) || document;
        var region = scope.querySelector('[data-fetch-region="' + name + '"]');
        if (!region) return;
        var url = new URL(region.getAttribute('data-fetch-region-src') || window.location.href, window.location.href);
        region.querySelectorAll('[name]').forEach(function (f) {
            if (!f.disabled && typeof f.value === 'string') url.searchParams.set(f.name, f.value);
        });
        _Z77.core.region.load(region, url.toString());
    }

    function bindBackdrop() {
        var root = _root();
        if (!root || root._z77PopupBound) return;
        root._z77PopupBound = true;
        root.addEventListener('close', _refreshAfterClose);
        // No close-on-backdrop-click: an edit is only discarded via the explicit
        // cancel control ([data-popup-close]) so a stray click — or a text
        // selection dragged onto the backdrop — never loses the form.
        root.addEventListener('click', function (e) {
            if (e.target.closest('[data-popup-fullscreen]')) { root.toggleAttribute('data-fullscreen'); return; }
            if (e.target.closest('[data-popup-close]')) close();
        });
    }

    return { show: show, close: close, bindBackdrop: bindBackdrop, refreshOnClose: refreshOnClose };
})();

/* ── clipboard channel ──────────────────────────────────────────────────────
 * Copies an element's text on click: `[data-copy="<selector>"]` on the trigger,
 * the selector naming the element that HOLDS the text. Module-agnostic — the
 * source may be a readonly <textarea>, an <input> or any plain element.
 */
_Z77.core.clipboard = (function () {

    /* Selecting first does two jobs: it shows the user WHAT was copied, and it
     * gives the legacy path something to work on — execCommand copies the
     * selection, nothing else. */
    function _select(el) {
        if (typeof el.select === 'function') {
            el.focus();
            el.select();
            if (typeof el.setSelectionRange === 'function') {
                el.setSelectionRange(0, (el.value || '').length);
            }
            return true;
        }
        var selection = window.getSelection();
        if (!selection) return false;
        var range = document.createRange();
        range.selectNodeContents(el);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    }

    function _legacy() {
        try { return document.execCommand('copy'); } catch (e) { return false; }
    }

    function _say(ok) {
        if (ok) {
            _Z77.core.flash.show('success', _Z77.core.i18n.t('js.copied', 'Kopiert'));
            return;
        }
        _Z77.core.flash.show('error', _Z77.core.i18n.t('js.copyFailed', 'Kopieren nicht möglich — bitte von Hand kopieren'));
    }

    /**
     * `navigator.clipboard` exists only in a secure context — over plain http
     * (a dev host) the legacy path is the ONLY one, which is why the selection
     * is made in both cases. Returns a promise resolving to whether it worked;
     * the user is told either way.
     */
    function copy(source) {
        if (!source) return Promise.resolve(false);

        var text     = ('value' in source) ? source.value : (source.textContent || '');
        var selected = _select(source);

        if (window.navigator.clipboard && window.isSecureContext) {
            return window.navigator.clipboard.writeText(text).then(
                function () { _say(true); return true; },
                function () { var ok = selected && _legacy(); _say(ok); return ok; }
            );
        }

        var ok = selected && _legacy();
        _say(ok);
        return Promise.resolve(ok);
    }

    return { copy: copy };
})();

/* ── field validation channel ───────────────────────────────────────────── */
_Z77.core.fields = (function () {
    var WRAPPER_SEL = '[data-z77-field-wrapper]';
    var ERROR_SEL   = '[data-z77-field-error]';

    function _wrapperOf(input) { return input.closest(WRAPPER_SEL); }

    function mark(scope, fieldName, message) {
        var input = scope.querySelector('[name="' + fieldName + '"]');
        if (!input) return;
        input.setAttribute('aria-invalid', 'true');
        var wrapper = _wrapperOf(input);
        if (!wrapper) return;
        var err = wrapper.querySelector(ERROR_SEL);
        if (!err) {
            err = document.createElement('small');
            err.setAttribute('data-z77-field-error', '');
            wrapper.appendChild(err);
        }
        err.textContent = message;
    }

    function clear(scope, fieldName) {
        var input = scope.querySelector('[name="' + fieldName + '"]');
        if (!input) return;
        input.setAttribute('aria-invalid', 'false');
        var wrapper = _wrapperOf(input);
        if (!wrapper) return;
        var err = wrapper.querySelector(ERROR_SEL);
        if (err) err.textContent = '';
    }

    function clearAll(scope) {
        scope.querySelectorAll('[aria-invalid="true"]').forEach(function (input) {
            input.setAttribute('aria-invalid', 'false');
            var wrapper = _wrapperOf(input);
            if (!wrapper) return;
            var err = wrapper.querySelector(ERROR_SEL);
            if (err) err.textContent = '';
        });
    }

    return { mark: mark, clear: clear, clearAll: clearAll };
})();

/* ── form data helper (generic) ─────────────────────────────────────────── */
function _z77CollectFormData(form) {
    var data = {};
    var multiNames = {};

    // Assigns name → value, honouring single-level bracket notation
    // (name="value[de]" → data.value.de) so grouped inputs arrive as a nested
    // map (e.g. the translation editor's per-language fields). Server reads it
    // as an associative array; a flat "value[de]" key would be silently dropped.
    function put(name, value) {
        var m = name.match(/^([^[\]]+)\[([^[\]]*)\]$/);
        if (m) {
            if (data[m[1]] === null || typeof data[m[1]] !== 'object') data[m[1]] = {};
            data[m[1]][m[2]] = value;
        } else {
            data[name] = value;
        }
    }

    form.querySelectorAll('input[type=checkbox][name]').forEach(function (cb) {
        multiNames[cb.name] = (multiNames[cb.name] || 0) + 1;
    });
    Array.from(form.elements).forEach(function (el) {
        if (!el.name || el.disabled) return;
        if (el.type === 'checkbox') {
            if (multiNames[el.name] > 1) {
                if (!Array.isArray(data[el.name])) data[el.name] = [];
                if (el.checked) data[el.name].push(el.value);
            } else if (/^[^[\]]+\[[^[\]]+\]$/.test(el.name)) {
                // A single keyed switch (name="roles[debtor]") nests like every other field —
                // left flat, the server looking for `roles` found nothing (2026-10-12).
                put(el.name, el.checked);
            } else {
                data[el.name] = el.checked;
            }
            return;
        }
        if (el.type === 'radio') {
            if (el.checked) put(el.name, el.value);
            return;
        }
        if (el.tagName === 'BUTTON') return;
        put(el.name, el.value);
    });
    return data;
}

/* ── check-url blur binding ─────────────────────────────────────────────── */
function _bindCheckUrl(scope) {
    scope.querySelectorAll('[data-fetch-post][data-check-url]').forEach(function (form) {
        if (form._z77CheckUrlBound) return;
        form._z77CheckUrlBound = true;
        var checkUrl = form.dataset.checkUrl;

        // Cancelling discards the form — the close control's mousedown fires
        // before the focused field's blur, so flag it and skip the check.
        var cancelling = false;
        form.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('[data-popup-close]')) {
                cancelling = true;
                setTimeout(function () { cancelling = false; }, 0);
            }
        }, true);

        Array.from(form.elements).forEach(function (input) {
            if (!input.name || input.tagName === 'BUTTON') return;
            if (input.type === 'hidden' || input.type === 'checkbox' || input.type === 'radio') return;
            // Only blur-validate a field the user actually edited — an untouched
            // (e.g. autofocused) field must not flag "required" just by being left.
            // Pristine required fields are still caught by the full check on submit.
            input.addEventListener('input', function () { input._z77Dirty = true; });
            input.addEventListener('blur', function (e) {
                if (cancelling) return;
                if (!input._z77Dirty) return;
                // Keyboard path: focus moving onto a cancel/close control.
                var next = e.relatedTarget;
                if (next && next.closest && next.closest('[data-popup-close]')) return;
                _Z77.core.fields.clear(form, input.name);
                _Z77.core.fetch.post(checkUrl, { field: input.name, value: input.value });
            });
        });
    });
}

/* ── busy guard ─────────────────────────────────────────────────────────────
 * One request per form (or trigger) at a time: a double click on «Speichern» created two
 * contacts or two journal entries (review 2026-10-13, H2). While the request runs the
 * owner carries `aria-busy="true"` and the control that sent it is disabled; both are
 * released when the request settles (`finally` — success, refusal or network error alike).
 * A second send while busy is ignored. This spares the server a duplicate, it does not
 * replace the server's own checks (Rule 7): a request can still be repeated by hand.
 *
 * guard(owner, control, send): `send` returns the request's promise; the answer is that
 * promise, or null when the owner was busy and nothing was sent. Collect the form's data
 * BEFORE calling it — a disabled control is not part of a form's data.
 */
_Z77.core.busy = (function () {
    function isBusy(owner) { return !!(owner && owner._z77Busy); }

    function guard(owner, control, send) {
        if (isBusy(owner)) return null;
        owner._z77Busy = true;
        owner.setAttribute('aria-busy', 'true');
        var held = control && !control.disabled && 'disabled' in control ? control : null;
        if (held) held.disabled = true;
        function release() {
            owner._z77Busy = false;
            owner.removeAttribute('aria-busy');
            if (held) held.disabled = false;
        }
        var request;
        try {
            request = Promise.resolve(send());
        } catch (e) {
            release();
            throw e;
        }
        return request.finally(release);
    }

    return { guard: guard, isBusy: isBusy };
})();

/* ── generic wire (data-fetch-post / data-fetch-get) ────────────────────── */
/* `ctx` (optional): {window: id} when the container is a window's body (ADR-047) — a
 * request fired from inside a window answers INTO that window. */
_Z77.core.wire = function (container, defaultPostUrl, ctx) {
    _Z77.core.dirty.track(container);
    _Z77.core.rows.init(container);
    _Z77.core.autosize.init(container);
    // A tree picker in fetched content (a window, a popup, a swapped region) is enhanced like
    // one on the page — otherwise its fallback list stays and a choice loads the whole page.
    _Z77.core.treePicker.init(container);
    _Z77.core.stickyTop.init(container);
    container.querySelectorAll('[data-fetch-post]').forEach(function (form) {
        var url = form.dataset.fetchPost || defaultPostUrl;
        if (!url) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (_Z77.core.busy.isBusy(form)) return;          // the first submit is still on its way
            var data = _z77CollectFormData(form);
            _Z77.core.busy.guard(form, e.submitter, function () {
                return _Z77.core.fetch.post(url, data, ctx);
            });
        });
    });
    container.querySelectorAll('[data-fetch-get]').forEach(function (el) {
        el.addEventListener('click', function () {
            if (el.hasAttribute('data-popup-refresh')) _Z77.core.popup.refreshOnClose(el);
            _Z77.core.busy.guard(el, el, function () {
                return _Z77.core.fetch.get(el.dataset.fetchGet, ctx);
            });
        });
    });
    // Copy to clipboard: the trigger names the element holding the text, so one
    // control serves a snippet box, a generated key or any readonly output.
    container.querySelectorAll('[data-copy]').forEach(function (el) {
        var selector = el.dataset.copy;
        if (!selector) return;                  // querySelector('') would throw
        el.addEventListener('click', function () {
            // Popup first, then document: an injected fragment carries its own
            // source, and an id may legitimately repeat behind the modal.
            _Z77.core.clipboard.copy(container.querySelector(selector)
                || document.querySelector(selector));
        });
    });
    // Inline status toggle: a checkbox POSTs its new state on change. Server is
    // authoritative (it persists + returns commands like set-class); on a failed
    // response the checkbox reverts so the UI never lies about stored state.
    // The switch is disabled while its request runs (busy guard) — a second flip cannot
    // race the first one's revert.
    container.querySelectorAll('[data-fetch-toggle]').forEach(function (el) {
        el.addEventListener('change', function () {
            var sent = _Z77.core.busy.guard(el, el, function () {
                return _Z77.core.fetch.post(el.dataset.fetchToggle, { value: el.checked })
                    .then(function (env) {
                        if (!env || env.status !== 'success') { el.checked = !el.checked; }
                    });
            });
            if (sent === null) el.checked = !el.checked;   // not sent — the switch must not lie
        });
    });
};

/* ── fetch + envelope dispatch with extensible handler registries ───────── */
_Z77.core.fetch = (function () {
    var _meta = document.querySelector('meta[name="csrf-token"]');
    var _csrfToken = _meta ? _meta.getAttribute('content') : '';

    var _envelopeHandlers = {};   // key → fn(value, envelope, sourceUrl, ctx)
    var _commandHandlers  = {};   // action → fn(payload, envelopeData, ctx)
    // ctx (optional, ADR-047): {window: id} — the window a request came from. Handlers that
    // do not care ignore the extra argument, so every existing handler keeps working.

    function registerEnvelopeHandler(key, fn) { _envelopeHandlers[key] = fn; }
    function registerCommand(action, fn)      { _commandHandlers[action] = fn; }

    function _executeCommands(commands, data, ctx) {
        (commands || []).forEach(function (cmd) {
            var handler = _commandHandlers[cmd.action];
            if (handler) handler(cmd, data, ctx);
        });
    }

    function _handleEnvelope(env, sourceUrl, ctx) {
        if (!env) return;
        Object.keys(env).forEach(function (key) {
            var handler = _envelopeHandlers[key];
            if (handler) handler(env[key], env, sourceUrl, ctx);
        });
    }

    /**
     * Extracts an embedded envelope from an HTML string, if present.
     * Server appends a `<script type="application/json" data-z77-envelope>...</script>`
     * tag when HtmlResponse carries flashes/messages/commands. We parse and
     * remove it before handing the HTML to the inject handler — keeps the
     * markup clean once it lands in the DOM.
     */
    function _extractEmbeddedEnvelope(html) {
        var re = /<script\s+type="application\/json"\s+data-z77-envelope>([\s\S]*?)<\/script>\s*/i;
        var m = html.match(re);
        if (!m) return { html: html, envelope: null };
        var envelope = null;
        try { envelope = JSON.parse(m[1]); } catch (e) { envelope = null; }
        return { html: html.replace(re, ''), envelope: envelope };
    }

    /* An answer a caller cannot use as its content (windows: an error status, or JSON where
     * HTML was asked for): a JSON envelope is dispatched like any other — a redirect to the
     * login after the session expired, a refusal's flash — and anything else (an error page)
     * becomes ONE error message with the status, never raw markup in the page. Resolves to
     * the envelope, or null. */
    function _unusable(r, sourceUrl, ctx) {
        var ct = r.headers.get('content-type') || '';
        function statusError() {
            _Z77.core.message.show('error', _Z77.core.i18n.t('js.requestFailed', 'Anfrage fehlgeschlagen') + ' (HTTP ' + r.status + ')');
            return null;
        }
        if (ct.indexOf('json') === -1) return Promise.resolve(statusError());
        return r.json().then(function (env) {
            _handleEnvelope(env, sourceUrl, ctx);
            return env;
        }, statusError);
    }

    function _parseResponse(r, sourceUrl, ctx) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('text/html') !== -1) {
            // An error page (a proxy's 502, a fatal) is no popup or window content.
            if (!r.ok) return _unusable(r, sourceUrl, ctx);
            return r.text().then(function (html) {
                var extracted = _extractEmbeddedEnvelope(html);
                var handler   = _envelopeHandlers['html'];
                if (handler) handler(extracted.html, extracted.envelope, sourceUrl, ctx);
                if (extracted.envelope) _handleEnvelope(extracted.envelope, sourceUrl, ctx);
                return { status: 'html', html: extracted.html, envelope: extracted.envelope };
            });
        }
        return r.json().then(function (env) {
            _handleEnvelope(env, sourceUrl, ctx);
            return env;
        });
    }

    /* A form as the browser would send it (multipart, `$_POST` on the server) — what a
     * window's plain `<form method="post">` sends (ADR-047), so a controller reads it with
     * getPostParameters() exactly as on a page load. The submitter's name/value rides along
     * (`op=save` vs `op=more`). */
    function postForm(url, formData, ctx) {
        return fetch(url, {
            method:  'POST',
            headers: { 'X-CSRF-Token': _csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            body:    formData
        })
        .then(function (r) { return _parseResponse(r, url, ctx); })
        .catch(function () { _Z77.core.message.show('error', _Z77.core.i18n.t('js.connectionError', 'Verbindungsfehler')); });
    }

    function post(url, data, ctx) {
        return fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     _csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data || {})
        })
        .then(function (r) { return _parseResponse(r, url, ctx); })
        .catch(function () { _Z77.core.message.show('error', _Z77.core.i18n.t('js.connectionError', 'Verbindungsfehler')); });
    }

    function get(url, ctx) {
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return _parseResponse(r, url, ctx); })
        .catch(function () { _Z77.core.message.show('error', _Z77.core.i18n.t('js.connectionError', 'Verbindungsfehler')); });
    }

    /* ── default envelope handlers ─────────────────────────────────────── */
    registerEnvelopeHandler('flashes', function (items) {
        (items || []).forEach(function (m) { _Z77.core.flash.show(m.type, m.text); });
    });
    registerEnvelopeHandler('messages', function (items) {
        (items || []).forEach(function (m) { _Z77.core.message.show(m.type, m.text); });
    });
    registerEnvelopeHandler('redirect', function (r) {
        if (!r) return;
        setTimeout(function () { window.location.href = r.url; }, r.delay || 0);
    });
    registerEnvelopeHandler('commands', function (cmds, env, _src, ctx) {
        _executeCommands(cmds, env && env.data, ctx);
    });
    // HTML from a window's own request that declares itself a window's content
    // ([data-window] — a re-rendered form with its errors, the read view again) fills that
    // window; any other HTML is a popup (a confirmation stays a modal above the windows).
    registerEnvelopeHandler('html', function (html, _env, sourceUrl, ctx) {
        var win = ctx && ctx.window ? _Z77.core.windows.byId(ctx.window) : null;
        if (win && /\sdata-window="/.test(html)) { _Z77.core.windows.fill(win, html, sourceUrl); return; }
        _Z77.core.popup.show(html, sourceUrl);
    });
    // A window's answer marks the fields of THAT window — the same name may stand on the page
    // behind it or in another window.
    registerEnvelopeHandler('fields', function (fields, _env, _src, ctx) {
        if (!fields) return;
        var scope = (ctx && ctx.window ? _Z77.core.windows.byId(ctx.window) : null) || document;
        Object.keys(fields).forEach(function (name) {
            var info = fields[name];
            if (info && info.valid === false) {
                _Z77.core.fields.mark(scope, name, info.message || '');
            }
        });
    });

    /* ── default generic commands (DOM ops, no module assumptions) ──────── */
    /* `origin` (optional, ADR-047): 'page' | 'region:<name>' | 'window:<id>' — the target
     * selector is resolved inside that part of the page; the controller got the origin from
     * the request (`_origin`) and hands it back. Without it: the whole document, as always. */
    function _find(p) {
        var scope = p.origin ? _Z77.core.windows.scope(p.origin) : document;
        return scope ? scope.querySelector(p.target) : null;
    }
    /* HTML a command brings in is WIRED like a popup's body (FETCH-ROW-001): a row that a save
     * replaced or inserted keeps its ⋮ (`data-fetch-get`), its switch (`data-fetch-toggle`),
     * its forms and blur checks alive — before this, such a row arrived dead until the next
     * page load, which is why every controller answered `reload`. The fragment is wired BEFORE
     * it enters the document (listeners survive the move; a fragment's querySelectorAll sees
     * its top-level nodes too). The wiring context is the window the TARGET lives in, not the
     * window the request came from: a page row's ⋮ must open on the page. */
    function _wiredFragment(html, origin) {
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        var ctx = origin && origin.indexOf('window:') === 0 ? { window: origin.slice(7) } : undefined;
        _Z77.core.wire(tpl.content, undefined, ctx);
        _bindCheckUrl(tpl.content);
        return tpl.content;
    }
    /* Keyboard focus survives the swap (as `region.load` does it): the focused control is
     * found again in the new markup by id, else by its toggle URL or name — a switch in a
     * replaced row usually has no id. */
    function _focusKey(el) {
        var a = document.activeElement;
        if (!a || !el.contains(a)) return '';
        if (a.id) return '#' + CSS.escape(a.id);
        if (a.dataset && a.dataset.fetchToggle) return '[data-fetch-toggle="' + CSS.escape(a.dataset.fetchToggle) + '"]';
        if (a.name) return '[name="' + CSS.escape(a.name) + '"]';
        return '';
    }
    /* The control to focus again, looked up ONLY in the nodes the swap brought in — a name
     * repeats in every row, and the neighbour row must not take the focus. */
    function _refocus(nodes, key) {
        if (!key) return;
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if (n.nodeType !== 1) continue;
            var hit = n.matches(key) ? n : n.querySelector(key);
            if (hit && typeof hit.focus === 'function') { hit.focus({ preventScroll: true }); return; }
        }
    }
    registerCommand('replace-html', function (p) {
        var el = _find(p);
        if (!el) return;
        var key = _focusKey(el);
        var frag = _wiredFragment(p.html, p.origin);
        var nodes = Array.prototype.slice.call(frag.childNodes);
        el.parentNode.replaceChild(frag, el);
        _refocus(nodes, key);
    });
    registerCommand('remove-element', function (p) {
        var el = _find(p);
        if (el) el.parentNode.removeChild(el);
    });
    registerCommand('insert-html', function (p) {
        var el = _find(p);
        if (!el) return;
        var frag = _wiredFragment(p.html, p.origin);
        switch (p.position) {
            case 'prepend': el.insertBefore(frag, el.firstChild); break;
            case 'before':  el.parentNode.insertBefore(frag, el); break;
            case 'after':   el.parentNode.insertBefore(frag, el.nextSibling); break;
            default:        el.appendChild(frag);
        }
    });
    registerCommand('update-text', function (p) {
        var el = _find(p);
        if (el) el.textContent = p.text;
    });
    registerCommand('update-html', function (p) {
        var el = _find(p);
        if (!el) return;
        el.textContent = '';
        el.appendChild(_wiredFragment(p.html, p.origin));
    });
    /* {action: 'set-value', target, value, untouched?, origin?} — a field takes a value the server
     * decided (first user: the debtor's price mode on a customer choice, owner 2026-10-18) and fires
     * a bubbling `change` when it changed, so everything that follows the field runs as on a
     * choice by hand. Only `.value` is written (Rule 7: no markup from data).
     * `untouched` (the «Betreffname» of a new document, owner 2026-10-21): written ONLY while the
     * field still holds what the server last put there — its `data-prefill`; what the user typed
     * stays. The new value becomes the field's `data-prefill`. */
    registerCommand('set-value', function (p) {
        var el = _find(p);
        if (!el || !('value' in el)) return;
        if (p.untouched) {
            if (!el.hasAttribute('data-prefill') || el.value !== el.getAttribute('data-prefill')) return;
            el.setAttribute('data-prefill', String(p.value));
        }
        if (el.value === String(p.value)) return;
        el.value = String(p.value);
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    /* {action: 'focus', target, origin?} — the field to go on with after a choice in another
     * window (first user: the order row's quantity after «Artikel wählen …», 2026-10-19). Runs
     * after `close-modal`, which hands the focus to the window below. */
    registerCommand('focus', function (p) {
        var el = _find(p);
        if (el && typeof el.focus === 'function') el.focus();
    });
    /* {action: 'add-row', target, kind, values?, focus?, origin?} — a row added to the
     * [data-rows] container `target` from its template `kind`, as its [data-row-add] button does
     * (core.js «rows»); `values` = { selector inside the new row: value } — each field takes
     * `.value` and fires a bubbling `change` (so «fill» runs as on a choice by hand); `focus` = a
     * selector inside the new row. First user: «+ Artikel» → the article picker (2026-10-19).
     * Without `focus` the focus stays where it was (the picker that stays open for the next
     * pick, 2026-10-20) — `rows.add` would otherwise move it into the new row behind. */
    registerCommand('add-row', function (p) {
        var box = _find(p);
        var keep = document.activeElement;
        var row = box ? _Z77.core.rows.add(box, p.kind) : null;
        if (!row) return;
        Object.keys(p.values || {}).forEach(function (selector) {
            var field = row.querySelector(selector);
            if (!field || !('value' in field)) return;
            field.value = String(p.values[selector]);
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
        var next = p.focus ? row.querySelector(p.focus) : null;
        if (next && typeof next.focus === 'function') next.focus();
        else if (!p.focus && keep && keep.isConnected && typeof keep.focus === 'function') keep.focus();
    });
    registerCommand('scroll-to', function (p) {
        var el = _find(p);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
    registerCommand('reload', function () { window.location.reload(); });
    /* Tells the window that shows this page in an iframe: {action: 'post-message',
     * message: {type: '…', …}} → window.parent.postMessage(message, own origin).
     * Same origin only (targetOrigin = this page's origin), so the message never
     * reaches a foreign parent; the receiver checks event.origin and the type.
     * Not framed (parent === window) → nothing. First user: the page editor
     * (ContentController::slotAction → content-edit.js, ADR-045 §4). */
    registerCommand('post-message', function (p) {
        if (window.parent === window || !p.message) return;
        window.parent.postMessage(p.message, window.location.origin);
    });

    /* ── popup commands ────────────────────────────────────────────────── */
    // From inside a window, «close the modal» means that window (ADR-047).
    // A host without the shared popup (the member shell opens native <dialog>s by
    // `data-dialog-open`) closes the open page dialog instead — one answer vocabulary
    // for both shells, no second command.
    // A request from a window that is gone by now (closed while the save ran) closes nothing:
    // the popup or page dialog open at this moment is not the one the request came from.
    registerCommand('close-modal', function (_p, _d, ctx) {
        if (ctx && ctx.window) {
            var win = _Z77.core.windows.byId(ctx.window);
            if (win) _Z77.core.windows.close(win, true);
            return;
        }
        if (document.querySelector('[data-z77-popup]')) { _Z77.core.popup.close(); return; }
        var dialog = document.querySelector('dialog[open]');
        if (dialog && typeof dialog.close === 'function') dialog.close();
    });

    /* ── windows (ADR-047) ─────────────────────────────────────────────── */
    // {action: 'close-window'} closes the window the request came from (or `window: id`),
    // with the windows opened from it. A save has already happened, so no question is asked.
    registerCommand('close-window', function (p, _d, ctx) {
        var win = _Z77.core.windows.byId(p.window || (ctx && ctx.window));
        if (win) _Z77.core.windows.close(win, true);
    });
    // {action: 'open-window', url, replace?: true, origin?} — a further window, or (replace)
    // a new content for the window the request came from (after a save: the read view again).
    registerCommand('open-window', function (p, _d, ctx) {
        var win = ctx && ctx.window ? _Z77.core.windows.byId(ctx.window) : null;
        if (p.replace && win) { _Z77.core.windows.load(win, p.url); return; }
        _Z77.core.windows.open(p.url, { origin: p.origin || '', parent: win ? win.getAttribute('data-z77-window') : '' });
    });
    // {action: 'refresh-region', name, origin?} — a fetch region reloads by its own address
    // (`data-fetch-region-src`, else the page's): the list behind a window, a total block.
    registerCommand('refresh-region', function (p) {
        var scope = p.origin ? _Z77.core.windows.scope(p.origin) : document;
        var region = scope ? scope.querySelector('[data-fetch-region="' + p.name + '"]') : null;
        if (region) _Z77.core.region.load(region, region.getAttribute('data-fetch-region-src') || window.location.href);
    });

    /* ── update [data-field] slots inside a container ──────────────────── */
    registerCommand('update-fields', function (p, data) {
        var container = _find(p);
        if (!container || !data) return;
        Object.keys(p.fields || {}).forEach(function (key) {
            var el = container.querySelector('[data-field="' + key + '"]');
            if (!el || data[key] === undefined) return;
            if (p.fields[key] === 'html') {
                // Server-rendered HTML, wired like every other fragment (FETCH-ROW-001).
                el.textContent = '';
                el.appendChild(_wiredFragment(String(data[key]), p.origin));
            } else {
                el.textContent = data[key];
            }
        });
    });

    /* ── lazy-loaded scripts with re-entrant init ───────────────────────
     * Server emits: {action: 'load-script', src: '/…/edit.js', init: 'navigation-edit', scope: '[data-z77-popup-body]'}
     * First call: appends <script src> to <head>, marks src loaded, runs the
     *   init function the script registered in _Z77.scriptInit.
     * Subsequent calls (same src): skips the network, just re-runs init —
     *   the script stays in the DOM, scope changes per popup-open.
     * `scope` and `init` are optional: scripts with pure side-effects can omit `init`.
     */
    var _loadedScripts = {};
    function _runInit(initName, scope) {
        if (!initName) return;
        var fn = _Z77.scriptInit[initName];
        if (typeof fn === 'function') fn(scope || document);
    }
    registerCommand('set-class', function (p) {
        var el = _find(p);
        if (!el) return;
        el.classList.toggle(p.class, !!p.on);
    });
    registerCommand('load-script', function (p) {
        var scope = p.scope ? document.querySelector(p.scope) : document;
        if (_loadedScripts[p.src] === true) {
            _runInit(p.init, scope);
            return;
        }
        if (_loadedScripts[p.src]) {                   // pending: queue init for onload
            _loadedScripts[p.src].push({ init: p.init, scope: scope });
            return;
        }
        var queue = [{ init: p.init, scope: scope }];
        _loadedScripts[p.src] = queue;
        var s = document.createElement('script');
        s.src   = p.src;
        s.defer = true;
        s.onload = function () {
            _loadedScripts[p.src] = true;
            queue.forEach(function (q) { _runInit(q.init, q.scope); });
        };
        s.onerror = function () {
            delete _loadedScripts[p.src];
            _Z77.core.message.show('error', _Z77.core.i18n.t('js.scriptLoadError', 'Script konnte nicht geladen werden') + ': ' + p.src);
        };
        document.head.appendChild(s);
    });

    return {
        post:     post,
        postForm: postForm,
        get:      get,
        extractEnvelope:         _extractEmbeddedEnvelope,
        handleEnvelope:          _handleEnvelope,
        unusable:                _unusable,
        registerEnvelopeHandler: registerEnvelopeHandler,
        registerCommand:         registerCommand
    };
})();

/* ── fetch regions ──────────────────────────────────────────────────────────
 * A part of a page that reloads ALONE, the rest untouched — first user: the
 * journal list below its capture form (FIN-JOURNAL-CAPTURE-001, owner
 * 2026-09-28): sorting, paging, searching must not throw away a half-typed
 * entry or its focus. Rule 7 justification: that is state in the rest of the
 * page, which a full reload cannot keep; CSS cannot fetch.
 *
 * Contract (progressive: without this script every link and form works as a
 * plain page load — the server renders the same markup either way):
 *   [data-fetch-region="<name>"]    the part that is replaced
 *   a[data-fetch-region-link]       inside it: a GET link that reloads only the region
 *   form[data-fetch-region-form]    inside it: a GET form, same (Enter searches)
 * The request goes to the link's / form's own URL in fetch mode; the server
 * answers the page's `main` (fetch skeleton), in which the region with the
 * same name is looked up and swapped in. The address bar follows
 * (history.replaceState), so a reload or a bookmark shows the same state.
 * Focus returns to the element with the same id when there is one; a focused
 * text field keeps what was typed during the load (and fires `input` when that
 * differs from the answer — «pick list» searches again). When the
 * answer carries no such region, the browser simply navigates there.
 */
_Z77.core.region = (function () {
    /* name → number of the latest load. Typing in a search or paging fast sends a second load
     * before the first one answered; answers can arrive in any order, and only the LATEST may
     * fill the region — an older one is dropped. Keyed by name, because a swap replaces the
     * element. */
    var _latest = {};

    function _load(region, url) {
        var name = region.getAttribute('data-fetch-region');
        // A region INSIDE a window (the customer picker, 2026-10-15) is not the page: its address
        // stays out of the address bar, and a failed load never navigates — the page behind holds
        // a half-filled form. It says so instead.
        var host = _Z77.core.windows.of(region);
        function fail() {
            region.removeAttribute('aria-busy');
            if (host) _Z77.core.message.show('error', _Z77.core.i18n.t('js.requestFailed', 'Anfrage fehlgeschlagen'));
            else window.location.href = url;
        }
        var ticket = (_latest[name] || 0) + 1;
        _latest[name] = ticket;
        var focusId = document.activeElement && region.contains(document.activeElement) ? document.activeElement.id : '';
        region.setAttribute('aria-busy', 'true');
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (_latest[name] !== ticket) return;      // a newer load owns the region
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('[data-fetch-region="' + name + '"]');
                if (!fresh) { fail(); return; }
                // What was typed while the load ran stays (a search as you type, «pick list»):
                // the focused text field keeps its value and fires `input`, so its own logic
                // follows the newer text.
                var typing = focusId ? document.getElementById(focusId) : null;
                var typed = typing && typing.tagName === 'INPUT' && /^(text|search)$/.test(typing.type) ? typing.value : null;
                region.replaceWith(fresh);
                if (!host) history.replaceState(history.state, '', url);
                // The new content is live like a popup's body: its ⋮, switches, fetch forms and
                // blur checks work (the region's own links and forms are delegated anyway). Inside
                // a window, its requests answer into that window.
                var win = _Z77.core.windows.of(fresh);
                _Z77.core.wire(fresh, undefined, win ? { window: win.getAttribute('data-z77-window') } : undefined);
                _bindCheckUrl(fresh);
                if (focusId) {
                    var el = fresh.querySelector('#' + CSS.escape(focusId));
                    if (el) {
                        if (typed !== null && el.value !== typed) {
                            el.value = typed;
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        el.focus();
                        if (typeof el.setSelectionRange === 'function' && typeof el.value === 'string') {
                            try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) { /* not a text field */ }
                        }
                    }
                }
            })
            .catch(function () {
                if (_latest[name] === ticket) fail();
            });
    }

    function bind() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[data-fetch-region-link]');
            if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var region = link.closest('[data-fetch-region]');
            if (!region) return;
            e.preventDefault();
            _load(region, link.href);
        });
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('form[data-fetch-region-form]');
            if (!form || e.defaultPrevented || (form.method || 'get').toLowerCase() !== 'get') return;
            var region = form.closest('[data-fetch-region]');
            if (!region) return;
            e.preventDefault();
            var url = new URL(form.action, window.location.href);
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, key) { if (value !== '') params.append(key, value); });   // empty fields stay out of the address
            url.search = params.toString();
            _load(region, url.toString());
        });
    }

    return { bind: bind, load: _load };
})();

/* ── windows (ADR-047) ─────────────────────────────────────────────────────
 * Controller-led windows: a click opens a record in a window, a save answers with
 * instructions, and nothing else on the page reloads. The CONTROLLER leads — it renders the
 * window's content, names the form's target, and answers a save with commands for the place
 * the window came from; this module only carries them out.
 *
 * Markup contract (data attributes only — module-agnostic, Rule 8):
 *   [data-window-open="<url>"]  a trigger: GET <url> in fetch mode, the answer becomes a window.
 *                               Keep an `href` on a link: without the script, and on a ctrl/⌘
 *                               click, it is a plain page load.
 *   [data-origin="<name>"]      (optional, on the trigger) the origin to report; default: the
 *                               window the trigger sits in (`window:<id>`), else the fetch
 *                               region (`region:<name>`), else `page`. Sent as `_origin`.
 *   [data-window-with="a b"]    (optional, on the trigger) the fields named a, b of the trigger's
 *                               form ride along as query parameters, with their value AT THE
 *                               CLICK (first user: the article picker gets the order's date and
 *                               price mode, 2026-10-19 — like «fill»'s `data-fill-with`)
 *   [autofocus]                 inside the content: takes the focus when the window opens or
 *                               loads (else the window itself)
 *   In the controller's answer, on the content's root element:
 *   [data-window="<mask>"]              the mask (form) this window is
 *   [data-window-entity="<type>:<id>"]  the record it shows
 *   [data-window-title="…"]             the title bar text
 *   [data-window-width="<length>"]      (optional) the width the controller wants for this content
 *                                       (e.g. `62rem`; rem/ch/px/%/vw) — a phone ignores it
 *   [data-window-confirm-close="…"]     ask this question before closing (the controller's call)
 *   [data-window-dismiss]               a chooser without edits: a click outside the window closes
 *                                       it too (first user: the article picker, 2026-10-20) — an
 *                                       edit window never gets it (no form is lost by a stray click)
 *   Inside a window:
 *   a[data-window-link]         loads its href into THIS window (read view ↔ edit form)
 *   [data-window-close]         closes this window (and its children); so does
 *                               [data-popup-close] inside a window, × and Esc
 *   [data-window-full]          toggles full screen (`is-full`, aria-pressed follows) — the icon
 *                               every window carries in its title bar (owner 2026-10-17)
 *   <form method="post">        is sent by fetch as FormData to its action; the answer is HTML
 *                               (this window's new content, e.g. errors) or an envelope
 *
 * Identity = mask + entity: the same mask on the same record opens ONCE (a second click brings
 * it to the front). Two masks on one record are allowed — unless an editable field (a named
 * input that is not hidden, disabled or read-only; `_…` and `csrf_token` excluded) is in both:
 * then the second does not open, the first comes to the front and a message names the field.
 * Placement (side by side, on top, draggable) is CSS only — nothing here depends on it.
 */
_Z77.core.windows = (function () {
    var _seq = 0;
    var _layer = null;

    function _container() {
        if (_layer) return _layer;
        _layer = document.createElement('div');
        _layer.className = 'z77-windows';
        _layer.setAttribute('data-z77-windows', '');
        _layer.hidden = true;
        document.body.appendChild(_layer);
        return _layer;
    }
    function all() {
        return _layer ? Array.prototype.slice.call(_layer.children).filter(function (w) { return w.hasAttribute('data-z77-window'); }) : [];
    }
    function byId(id) {
        return id ? all().filter(function (w) { return w.getAttribute('data-z77-window') === String(id); })[0] || null : null;
    }
    function of(el) { return el && el.closest ? el.closest('[data-z77-window]') : null; }

    /* The page behind is inert while a window is open; the windows among themselves are not. */
    function _inert(on) {
        Array.prototype.forEach.call(document.body.children, function (c) {
            // The message and flash channels stay live: a message about a window (a field
            // conflict, «gespeichert») must be readable and closable above the windows.
            // The help window (ADR-048) is read beside the form: it stays live as well.
            if (c === _layer || c.tagName === 'SCRIPT' || c.tagName === 'DIALOG' || c.id === 'messages' || c.id === 'flash-messages' || c.hasAttribute('data-z77-help')) return;
            if (on) c.setAttribute('inert', ''); else c.removeAttribute('inert');
        });
    }

    function front(win) {
        all().forEach(function (w) { w.classList.toggle('is-front', w === win); });
    }

    function _editable(root) {
        var names = {};
        root.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
            var type = (el.getAttribute('type') || '').toLowerCase();
            if (type === 'hidden' || type === 'submit' || type === 'button' || el.disabled || el.readOnly) return;
            var name = el.name.replace(/\[\]$/, '');
            if (name.charAt(0) === '_' || name === 'csrf_token') return;
            names[name] = true;
        });
        return Object.keys(names);
    }

    function _identity(root) {
        var mask   = root ? root.getAttribute('data-window') : null;
        var entity = root ? root.getAttribute('data-window-entity') : null;
        return mask && entity ? { mask: mask, entity: entity, key: mask + '|' + entity } : null;
    }

    /* The open window this content may not open beside — and why. */
    function _conflict(root) {
        var id = _identity(root);
        if (!id) return null;
        var fields = _editable(root);
        var hit = null;
        all().some(function (w) {
            var other = w._z77Identity;
            if (!other) return false;
            if (other.key === id.key) { hit = { win: w }; return true; }
            if (other.entity !== id.entity) return false;
            var shared = fields.filter(function (f) { return (w._z77Fields || []).indexOf(f) !== -1; });
            if (shared.length) {
                hit = { win: w, message: _Z77.core.i18n.t('js.windowFieldOpen', 'Das Feld ist bereits in einem offenen Fenster in Bearbeitung') + ': ' + shared.join(', ') };
                return true;
            }
            return false;
        });
        return hit;
    }

    /* The full-screen icon of the title bar (the owner's draft, 2026-10-17): four corners pointing
     * out, pointing in while the window is full. Built node by node — no markup from a string. */
    var _FULL_OUT = 'M2 6V2h4M10 2h4v4M14 10v4h-4M6 14H2v-4';
    var _FULL_IN  = 'M6 2v4H2M14 6h-4V2M10 14v-4h4M2 10h4v4';
    function _fullIcon() {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 16 16');
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS(ns, 'path');
        path.setAttribute('d', _FULL_OUT);
        svg.appendChild(path);
        return svg;
    }

    function _build(parentId, origin) {
        var win = document.createElement('section');
        win.className = 'z77-window';
        win.setAttribute('data-z77-window', String(++_seq));
        win.setAttribute('role', 'dialog');
        win.setAttribute('aria-modal', 'true');
        win.tabIndex = -1;
        if (parentId) win.setAttribute('data-z77-window-parent', parentId);
        win._z77Origin = origin || 'page';
        // Built node by node: the translated label goes in by setAttribute, never through
        // markup (a quote in a translation would break out of the attribute).
        var head = document.createElement('header');
        head.className = 'z77-window__head';
        var title = document.createElement('span');
        title.className = 'z77-window__title';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'z77-window__close';
        close.setAttribute('data-window-close', '');
        close.setAttribute('aria-label', _Z77.core.i18n.t('common.close', 'Schliessen'));
        close.textContent = '×';
        var fullLabel = _Z77.core.i18n.t('common.fullscreen', 'Vollbild');
        var full = document.createElement('button');
        full.type = 'button';
        full.className = 'z77-window__full';
        full.setAttribute('data-window-full', '');
        full.setAttribute('aria-label', fullLabel);
        full.setAttribute('aria-pressed', 'false');
        full.title = fullLabel;
        full.appendChild(_fullIcon());
        head.appendChild(title);
        head.appendChild(full);
        head.appendChild(close);
        var body = document.createElement('div');
        body.className = 'z77-window__body';
        win.appendChild(head);
        win.appendChild(body);
        return win;
    }

    /* The «Hilfe» button in the title bar (ADR-048): there while the content carries a help
     * template, gone when new content (read view ↔ edit form) has none. It says the word — an
     * icon stands for a word only where there is no room for it (owner 2026-10-10).
     * With the KI-Assistent on the page (bot.js sets `data-kibot-ready` on <html>, ADR-055) the
     * button is the assistant's opener: «KI-Assistent», the window's help topic as context;
     * bot.js takes the click, its «Hilfetext anzeigen» hands it back to the help here. */
    function _helpButton(win, body) {
        var head = win.querySelector('.z77-window__head');
        var btn  = head.querySelector('[data-help-open]');
        var tpl  = body.querySelector('template[data-help]');
        if (btn) btn.parentNode.removeChild(btn);   // new content, new help (or none)
        if (!tpl) return;
        var bot   = document.documentElement.hasAttribute('data-kibot-ready');
        var label = bot ? _Z77.core.i18n.t('common.assistant', 'KI-Assistent') : _Z77.core.i18n.t('common.help', 'Hilfe');
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'z77-help-open';
        btn.setAttribute('data-help-open', '');
        btn.setAttribute('aria-label', label);
        btn.title = label;
        btn.textContent = label;
        if (bot) {
            btn.setAttribute('data-kibot-open', '');
            btn.setAttribute('data-kibot-fallback', '');
            btn.setAttribute('data-kibot-context', tpl.getAttribute('data-help-topic') || '');
            btn.setAttribute('data-kibot-context-label', (tpl.getAttribute('data-help-title') || '').replace(/^Hilfe\s*:\s*/, ''));
        }
        head.insertBefore(btn, head.querySelector('.z77-window__full') || head.querySelector('.z77-window__close'));
    }

    /* New content for a window: the controller's answer (HTML, fetch skeleton = `main`). */
    function fill(win, html, sourceUrl) {
        var extracted = _Z77.core.fetch.extractEnvelope(html);
        var body = win.querySelector('.z77-window__body');
        body.innerHTML = extracted.html;
        var root = body.querySelector('[data-window]') || body.firstElementChild;
        win._z77Src      = sourceUrl;
        win._z77Identity = _identity(root);
        win._z77Fields   = root ? _editable(root) : [];
        win.querySelector('.z77-window__title').textContent = root ? (root.getAttribute('data-window-title') || '') : '';
        // The controller knows how wide its content must be (a form row must not scroll sideways).
        var width = root ? root.getAttribute('data-window-width') : null;
        if (width && /^\d{1,3}(\.\d{1,2})?(rem|ch|px|%|vw)$/.test(width)) win.style.setProperty('--z77-window-width', width);
        else win.style.removeProperty('--z77-window-width');
        _helpButton(win, body);
        var ctx = { window: win.getAttribute('data-z77-window') };
        _Z77.core.wire(body, sourceUrl, ctx);
        _bindCheckUrl(body);
        if (extracted.envelope) _Z77.core.fetch.handleEnvelope(extracted.envelope, sourceUrl, ctx);
    }

    function _url(url, origin) {
        var u = new URL(url, window.location.href);
        if (origin) u.searchParams.set('_origin', origin);
        return u.toString();
    }

    /* A window's content is HTML with a success status. Anything else — an error status, or
     * an envelope (the session expired → redirect to the login; a refusal) — goes through
     * the envelope dispatch / one error message (`fetch.unusable`), never as raw text into
     * a window. Resolves to the HTML, or null when there is no window content. */
    function _get(url, ctx) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (r.ok && ct.indexOf('text/html') !== -1) return r.text();
            return _Z77.core.fetch.unusable(r, url, ctx).then(function () { return null; });
        });
    }

    /* Opens <url> as a window — or brings the window that already shows it to the front. */
    function open(url, opts) {
        opts = opts || {};
        var target = _url(url, opts.origin);
        return _get(target).then(function (html) {
            if (html === null) return null;
            // A <template> parses without running anything: no image loads, no handler fires
            // for content that may never be shown (a conflict brings another window forward).
            var probe = document.createElement('template');
            probe.innerHTML = _Z77.core.fetch.extractEnvelope(html).html;
            var hit = _conflict(probe.content.querySelector('[data-window]'));
            if (hit) {
                front(hit.win);
                hit.win.focus();
                if (hit.message) _Z77.core.message.show('error', hit.message);
                return hit.win;
            }
            var win = _build(opts.parent || '', opts.origin);
            var layer = _container();
            layer.appendChild(win);
            if (layer.hidden) { layer.hidden = false; _inert(true); }
            fill(win, html, target);
            front(win);
            _focusIn(win);
            return win;
        }).catch(function () { window.location.href = url; });
    }

    /* Loads <url> into an open window (read view ↔ edit form); the origin stays the window's. */
    function load(win, url) {
        var target = _url(url, win._z77Origin);
        return _get(target, { window: win.getAttribute('data-z77-window') }).then(function (html) {
            if (html === null || !win.isConnected) return;
            fill(win, html, target);
            front(win);
            var auto = win.querySelector('[autofocus]');
            if (auto) auto.focus();
        });
    }

    /* The content's [autofocus] field (a picker's search), else the window itself. */
    function _focusIn(win) {
        var auto = win.querySelector('[autofocus]');
        (auto && typeof auto.focus === 'function' ? auto : win).focus();
    }

    /* The trigger's URL with its [data-window-with] fields as they stand now. */
    function _withFields(opener, url) {
        var names = (opener.getAttribute('data-window-with') || '').split(/\s+/).filter(Boolean);
        var form = opener.form || opener.closest('form');
        if (!names.length || !form) return url;
        var u = new URL(url, window.location.href);
        names.forEach(function (name) {
            var el = form.elements.namedItem(name);
            if (el && typeof el.value === 'string') u.searchParams.set(name, el.value);
        });
        return u.toString();
    }

    /* Closes a window and the windows opened from it. `force` skips the controller's question. */
    function close(win, force) {
        if (!win) return false;
        var id = win.getAttribute('data-z77-window');
        var children = all().filter(function (w) { return w.getAttribute('data-z77-window-parent') === id; });
        for (var i = 0; i < children.length; i++) {
            if (!close(children[i], force)) return false;
        }
        var ask = win.querySelector('[data-window-confirm-close]');
        if (!force && ask && !window.confirm(ask.getAttribute('data-window-confirm-close'))) return false;
        // Unsaved changes (form[data-dirty], «dirty forms») — asked once, unless the controller asked above.
        if (!force && !ask && _Z77.core.dirty.isDirty(win)
            && !window.confirm(_Z77.core.i18n.t('js.window.discard_changes', 'Ungespeicherte Änderungen verwerfen?'))) return false;
        win.parentNode.removeChild(win);
        var rest = all();
        if (!rest.length) { _layer.hidden = true; _inert(false); }
        else { front(rest[rest.length - 1]); rest[rest.length - 1].focus(); }
        return true;
    }

    /* 'page' → document; 'region:<name>' → that fetch region; 'window:<id>' → that window. */
    function scope(origin) {
        if (!origin || origin === 'page') return document;
        var i = origin.indexOf(':');
        var kind = origin.slice(0, i), name = origin.slice(i + 1);
        if (kind === 'window') return byId(name);
        if (kind === 'region') return document.querySelector('[data-fetch-region="' + name + '"]');
        return document;
    }

    function _originOf(el) {
        var own = el.getAttribute('data-origin');
        if (own) return own;
        var win = of(el);
        if (win) return 'window:' + win.getAttribute('data-z77-window');
        var region = el.closest('[data-fetch-region]');
        return region ? 'region:' + region.getAttribute('data-fetch-region') : 'page';
    }

    function bind() {
        document.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            // A chooser closes on a click outside it — not on one into the message channels, and
            // not on a target a re-render just removed (it is no longer anywhere).
            if (e.target.isConnected && !e.target.closest('#messages, #flash-messages, [data-z77-help]')) {
                all().forEach(function (w) {
                    if (!w.contains(e.target) && w.querySelector('[data-window-dismiss]')) close(w);
                });
            }
            var opener = e.target.closest('[data-window-open]');
            if (opener) {
                e.preventDefault();
                var parent = of(opener);
                open(_withFields(opener, opener.getAttribute('data-window-open')), {
                    origin: _originOf(opener),
                    parent: parent ? parent.getAttribute('data-z77-window') : ''
                });
                return;
            }
            var win = of(e.target);
            if (!win) return;
            var link = e.target.closest('a[data-window-link]');
            if (link) { e.preventDefault(); load(win, link.href); return; }
            var fullBtn = e.target.closest('[data-window-full]');
            if (fullBtn) {
                var isFull = win.classList.toggle('is-full');
                fullBtn.setAttribute('aria-pressed', String(isFull));
                var fullPath = fullBtn.querySelector('path');
                if (fullPath) fullPath.setAttribute('d', isFull ? _FULL_IN : _FULL_OUT);
                front(win);
                return;
            }
            if (e.target.closest('[data-window-close], [data-popup-close]')) { close(win); return; }
            front(win);
        });
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var win = of(form);
            if (!win || e.defaultPrevented || form.hasAttribute('data-fetch-post')) return;
            var sub = e.submitter || null;
            var method = (sub && sub.getAttribute('formmethod')) || form.getAttribute('method') || 'get';
            if (method.toLowerCase() !== 'post') return;
            // A form (or button) that answers into another tab — a PDF, a booking print — is
            // the browser's job: sent natively, the window stays as it is.
            var target = (sub && sub.getAttribute('formtarget')) || form.getAttribute('target') || '';
            if (target !== '' && target !== '_self') return;
            e.preventDefault();
            if (_Z77.core.busy.isBusy(form)) return;          // the first submit is still on its way
            var data = sub ? new FormData(form, sub) : new FormData(form);
            var action = sub && sub.hasAttribute('formaction') ? sub.formAction : form.action;
            _Z77.core.busy.guard(form, sub, function () {
                return _Z77.core.fetch.postForm(action, data, { window: win.getAttribute('data-z77-window') });
            });
        });
        document.addEventListener('keydown', function (e) {
            // A control that used Esc itself (an open tree picker closes its popover) keeps the window.
            if (e.key !== 'Escape' || e.defaultPrevented || !all().length || document.querySelector('dialog[open]')) return;
            var win = _layer.querySelector('.is-front') || all()[all().length - 1];
            if (win) { e.preventDefault(); close(win); }
        });
    }

    return { open: open, load: load, fill: fill, close: close, front: front, byId: byId, of: of, scope: scope, all: all, bind: bind };
})();

/* ── help (ADR-048) ────────────────────────────────────────────────────────
 * The help window: the text a controller attached to its answer, opened by an «i» and read
 * BESIDE the form. Not modal — the page and the windows stay usable; it stays open until it
 * is closed or the page changes (closing a form's window does not close it; Esc does not
 * either — Esc belongs to the front window).
 *
 * Markup contract (data attributes only — module-agnostic, Rule 8):
 *   <template data-help data-help-title="…">…</template>
 *                               the help, rendered by HelpService at the end of `main` (page and
 *                               fetch mode — so a window's body carries it too). Inert until opened.
 *   [data-help-open]            opens the help that belongs to where it stands — inside a
 *                               window that window's template; outside a window (the top bar's
 *                               «? Hilfe») the template of the window that holds the last focused
 *                               field, else the first template of the page outside any window.
 *                               No template → nothing happens. A shell renders «? Hilfe»
 *                               (`partials/helpTrigger`, `[data-help-trigger]`, server-side
 *                               `hidden` — revealed here, so without JS there is no button);
 *                               `windows.fill()` adds the «i» to a window's title bar.
 *   [data-help-field="<key>"]   a section INSIDE the help template about one field (owner
 *                               2026-10-08). Opening scrolls to the section of the field focused
 *                               last and marks it `is-help-hit` for 1.6 s; no match → the top
 *                               (the general part).
 *   [data-help-key="<key>"]     on a form control: the key its help section carries, when it is
 *                               not the control's `name` (default: `name` without `[…]`, so
 *                               `debit[]` → `debit`).
 *   F1 in a form control        opens the same help (preventDefault only when there is help).
 *   Inside the help window (built here):
 *   [data-help-full]            toggles full screen (`is-full`, aria-pressed follows)
 *   [data-help-close]           closes the help window
 *
 * ONE help window: a second «i» replaces its content and title. Placement: docked to the right,
 * full height (`is-docked`); dragging the title bar makes it float (`is-floating`). Resizing is
 * CSS (`resize`), the geometry lives in kernel/shared `_help.scss`, the look in the host.
 */
_Z77.core.help = (function () {
    var _win    = null;
    var _last   = null;   // the form control focused last outside the help window
    var _HIT_MS = 1600;   // the fade in `_help.scss` (`is-help-hit`)

    function _build() {
        var t = _Z77.core.i18n.t;
        _win = document.createElement('aside');
        _win.className = 'z77-help is-docked';
        _win.setAttribute('data-z77-help', '');
        _win.setAttribute('role', 'complementary');
        _win.setAttribute('aria-labelledby', 'z77-help-title');
        // Node by node: translated labels go in by setAttribute, never through markup.
        var head = document.createElement('header');
        head.className = 'z77-help__head';
        var title = document.createElement('span');
        title.className = 'z77-help__title';
        title.id = 'z77-help-title';
        head.appendChild(title);
        head.appendChild(_headButton('z77-help__full', 'data-help-full', t('common.fullscreen', 'Vollbild'), '⤢'));
        head.appendChild(_headButton('z77-help__close', 'data-help-close', t('common.close', 'Schliessen'), '×'));
        head.querySelector('[data-help-full]').setAttribute('aria-pressed', 'false');
        var body = document.createElement('div');
        body.className = 'z77-help__body';
        _win.appendChild(head);
        _win.appendChild(body);
        document.body.appendChild(_win);
        _drag(head);
        return _win;
    }

    function _headButton(className, marker, label, glyph) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = className;
        btn.setAttribute(marker, '');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
        btn.textContent = glyph;
        return btn;
    }

    /* The help of a window, or of the page (the first template outside any window). */
    function _templateIn(win) {
        if (win) return win.querySelector('.z77-window__body template[data-help]');
        var all = document.querySelectorAll('template[data-help]');
        for (var i = 0; i < all.length; i++) {
            if (!_Z77.core.windows.of(all[i])) return all[i];
        }
        return null;
    }

    /* The help that belongs to where the opener stands. Outside a window (the top bar) the
     * last focused field decides: in a window with help → that help, else the page's. */
    function _templateFor(el) {
        var win = _Z77.core.windows.of(el);
        if (win) return _templateIn(win);
        if (_last && _last.isConnected) {
            var lastWin = _Z77.core.windows.of(_last);
            var tpl = lastWin ? _templateIn(lastWin) : null;
            if (tpl) return tpl;
        }
        return _templateIn(null);
    }

    /* A field the help can be about: a form control, not one of the help window's own. */
    function _isField(el) {
        if (!el || !el.matches || !el.matches('input, select, textarea')) return false;
        if (/^(hidden|submit|button|reset|image)$/i.test(el.type || '')) return false;
        return !el.closest('[data-z77-help]');
    }

    /* The section key of a field: `data-help-key`, else its name without brackets. */
    function _keyOf(el) {
        var key = el.getAttribute('data-help-key');
        if (key) return key;
        return (el.getAttribute('name') || '').replace(/\[[^\]]*\]$/, '');
    }

    /* The key of the last field — only when it lies in the same scope (page / window) as
     * the help being opened; a field of another window says nothing about this help. */
    function _lastKeyFor(tpl) {
        if (!_last || !_last.isConnected) return '';
        return _Z77.core.windows.of(_last) === _Z77.core.windows.of(tpl) ? _keyOf(_last) : '';
    }

    /* Scrolls the help body to the section about `key` and marks it; none → the top.
     * Compares attribute values — the key never goes into a selector. */
    function _reveal(body, key) {
        body.scrollTop = 0;
        if (!key) return;
        var all = body.querySelectorAll('[data-help-field]'), sec = null;
        for (var i = 0; i < all.length; i++) {
            if (all[i].getAttribute('data-help-field') === key) { sec = all[i]; break; }
        }
        if (!sec) return;
        body.scrollTop = sec.getBoundingClientRect().top - body.getBoundingClientRect().top - 8;
        sec.classList.add('is-help-hit');
        setTimeout(function () { sec.classList.remove('is-help-hit'); }, _HIT_MS);
    }

    /* Shows <template>'s content in the help window (opens it, or replaces what it shows),
     * at the section of `key` when the help has one. */
    function open(tpl, key) {
        if (!tpl || !tpl.content) return null;
        var win = _win || _build();
        win.querySelector('.z77-help__title').textContent = tpl.getAttribute('data-help-title') || _Z77.core.i18n.t('common.help', 'Hilfe');
        var body = win.querySelector('.z77-help__body');
        body.textContent = '';
        body.appendChild(tpl.content.cloneNode(true));
        _reveal(body, key || '');
        return win;
    }

    /* Removes the help window; the next «i» builds a fresh one, docked again. */
    function close() {
        if (_win && _win.parentNode) _win.parentNode.removeChild(_win);
        _win = null;
    }

    function _full(on) {
        _win.classList.toggle('is-full', on);
        _win.querySelector('[data-help-full]').setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    /* Dragging the title bar. JavaScript because CSS cannot move an element by pointer
     * (rule 7) — it only sets left/top; size stays CSS `resize`, the rest stays in the SCSS. */
    function _drag(head) {
        var dx = 0, dy = 0, active = false;
        head.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('button') || _win.classList.contains('is-full')) return;
            var r = _win.getBoundingClientRect();
            dx = e.clientX - r.left;
            dy = e.clientY - r.top;
            active = true;
            head.setPointerCapture(e.pointerId);
            e.preventDefault();   // no text selection while dragging
        });
        head.addEventListener('pointermove', function (e) {
            if (!active) return;
            var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
            if (_win.classList.contains('is-docked')) {
                // Leaving the dock: keep the width, give up the full height.
                var r = _win.getBoundingClientRect();
                _win.style.width  = r.width + 'px';
                _win.style.height = Math.min(r.height, Math.round(vh * 0.7)) + 'px';
                _win.classList.remove('is-docked');
                _win.classList.add('is-floating');
            }
            var w = _win.offsetWidth, h = _win.offsetHeight;
            _win.style.left = Math.max(0, Math.min(e.clientX - dx, vw - w)) + 'px';
            _win.style.top  = Math.max(0, Math.min(e.clientY - dy, vh - h)) + 'px';
        });
        function stop(e) {
            if (!active) return;
            active = false;
            if (head.hasPointerCapture(e.pointerId)) head.releasePointerCapture(e.pointerId);
        }
        head.addEventListener('pointerup', stop);
        head.addEventListener('pointercancel', stop);
    }

    function bind() {
        // The trigger is rendered `hidden` (no script, no help window — no button).
        var triggers = document.querySelectorAll('[data-help-trigger][hidden]');
        for (var i = 0; i < triggers.length; i++) triggers[i].hidden = false;

        document.addEventListener('focusin', function (e) {
            if (_isField(e.target)) _last = e.target;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'F1' || e.altKey || e.ctrlKey || e.metaKey || !_isField(e.target)) return;
            var tpl = _templateIn(_Z77.core.windows.of(e.target));
            if (!tpl) return;   // no help here: F1 stays the browser's
            e.preventDefault();
            _last = e.target;
            open(tpl, _keyOf(e.target));
        });
        document.addEventListener('click', function (e) {
            if (e.button !== 0) return;
            var opener = e.target.closest('[data-help-open]');
            if (opener) {
                e.preventDefault();
                var tpl = _templateFor(opener);
                open(tpl, tpl ? _lastKeyFor(tpl) : '');
                return;
            }
            if (!_win || !_win.contains(e.target)) return;
            if (e.target.closest('[data-help-close]')) { close(); return; }
            if (e.target.closest('[data-help-full]')) _full(!_win.classList.contains('is-full'));
        });
    }

    return { open: open, close: close, bind: bind };
})();

/* ── submit-navigate ───────────────────────────────────────────────────────
 * A form that answers into a NEW TAB (`target="_blank"`) leaves the page it was sent from
 * stale — first user: the booking print of module-debtor (owner 2026-10-10), whose POST books
 * the documents and answers their PDF in the new tab, while the confirmation page stays
 * behind showing drafts that are booked by now. With `data-submit-navigate="<url>"` this tab
 * goes to <url> right after the submit (same origin only); the server is untouched by it.
 *
 * Rule 7: nothing here decides anything — the form is sent natively, the server books and
 * answers; this only moves the old tab on. Without the script the page simply stays (the
 * template offers a link onwards). The navigation waits a moment so the browser has sent the
 * POST first; the backend holds the session for the whole request, so the GET of <url> is
 * served after the POST has finished.
 */
_Z77.core.submitNavigate = (function () {
    var DELAY_MS = 400;

    function bind() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches || !form.matches('form[target="_blank"][data-submit-navigate]')) return;
            var url = new URL(form.getAttribute('data-submit-navigate'), window.location.href);
            if (url.origin !== window.location.origin) return;
            // After every other handler ran: a submit another script took over is not followed.
            setTimeout(function () {
                if (e.defaultPrevented) return;
                setTimeout(function () { window.location.assign(url.href); }, DELAY_MS);
            }, 0);
        });
    }

    return { bind: bind };
})();

/* ── fill ───────────────────────────────────────────────────────────────────
 * A choice that fills its neighbours — first user: the article of an order line fills text,
 * unit, price, tax code and account (owner 2026-10-15). The server decides every value; the
 * script only asks and writes `.value` (Rule 7: no markup from data). Without the script the
 * fields stay as typed and the save fills them.
 *
 * Contract:
 *   [data-fill-url="<url>"]       on an input / select: on change GETs <url>?value=<its value>
 *   [data-fill-with="a b"]        optional: the form fields named a, b ride along (?a=…&b=…)
 *   [data-fill-scope]             the ancestor whose fields are filled (a line row)
 *   [data-fill-key="<key>"]       a field inside the scope the answer may set
 * The answer is a fetch envelope; `data.fill` = { key: value } sets those fields (each then fires
 * a bubbling `input` event), `data.lock` = { key: true|false } locks a field (a select disabled,
 * an input read-only) or frees it. Flashes and an error status go the envelope's usual way; an
 * error fills nothing. «price mode» asks the same source with `prices=1` and reads only
 * `data.prices` = { net: '…', gross: '…' } — the exact prices of the choice in both modes.
 */
_Z77.core.fill = (function () {
    function _apply(scope, data) {
        var fill = (data && data.fill) || {};
        var lock = (data && data.lock) || {};
        scope.querySelectorAll('[data-fill-key]').forEach(function (field) {
            var key = field.getAttribute('data-fill-key');
            if (Object.prototype.hasOwnProperty.call(fill, key)) field.value = fill[key];
            if (Object.prototype.hasOwnProperty.call(lock, key)) {
                if (field.tagName === 'SELECT') field.disabled = !!lock[key];
                else field.readOnly = !!lock[key];
            }
            // A written value is an edit like a typed one: «dirty forms», «autosize» and «line
            // sums» follow it (2026-10-18).
            if (Object.prototype.hasOwnProperty.call(fill, key)) field.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    /* The request a source makes — `value` + its `data-fill-with` fields + $extra; the promise of
     * the envelope. Also asked by «price mode» (with `prices=1`). */
    function ask(source, extra) {
        var params = new URLSearchParams();
        params.set('value', source.value.trim());
        var form = source.form;
        (source.getAttribute('data-fill-with') || '').split(/\s+/).forEach(function (name) {
            var el = name && form ? form.elements.namedItem(name) : null;
            if (el && typeof el.value === 'string') params.set(name, el.value);
        });
        Object.keys(extra || {}).forEach(function (key) { params.set(key, extra[key]); });
        var url = source.getAttribute('data-fill-url');
        return _Z77.core.fetch.get(url + (url.indexOf('?') === -1 ? '?' : '&') + params.toString());
    }

    function _request(source) {
        var scope = source.closest('[data-fill-scope]');
        if (!scope) return;
        ask(source).then(function (env) {
            if (env && env.status === 'success') _apply(scope, env.data);
        });
    }

    function bind() {
        document.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('[data-fill-url]')) _request(e.target);
        });
    }

    return { bind: bind, ask: ask };
})();

/* ── dirty forms ────────────────────────────────────────────────────────────
 * A form that knows whether it holds unsaved changes (owner 2026-10-18): its save is quiet while
 * there is nothing to save and calls when there is; a window asks before it drops changes. The
 * script only compares and shows — the server saves and checks whatever comes (Rule 7). Without
 * the script the save stays a plain, active button.
 *
 * Contract:
 *   form[data-dirty="clean|dirty"]  tracked; the server's value is the START: 'dirty' = what the
 *                                   form shows is not saved (a new record, an answer with errors)
 *                                   and stays dirty; 'clean' = compared with the state at load
 *   [data-dirty-submit]             the save inside it: `aria-disabled` follows, and while clean
 *                                   it does not submit (a click, Enter) — other submits pass
 *   input[type=hidden][name="_dirty_base"]  optional: carries the hash of the LOADED state
 *                                   through a POST that re-renders without saving (the server
 *                                   echoes the posted value) — set back, the form is clean again
 * The state is written back to `data-dirty` (CSS styles the save from it). Compared are the
 * named fields a submit would send, without `_…`, `csrf_token`, `entity_csrf` and `version`;
 * a field set back to its old value makes the form clean again. A part the server replaces
 * (`replace-html`) is compared too (MutationObserver).
 */
_Z77.core.dirty = (function () {
    var SKIP = { csrf_token: 1, entity_csrf: 1, version: 1 };

    function _snapshot(form) {
        var parts = [];
        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (!el.name || el.disabled || el.name.charAt(0) === '_' || SKIP[el.name]) continue;
            if (/^(submit|button|reset|file)$/.test(el.type)) continue;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
            if (el.tagName === 'SELECT' && el.multiple) {
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) parts.push(el.name + '=' + el.options[j].value);
                }
                continue;
            }
            parts.push(el.name + '=' + el.value);
        }
        return parts.join('\n');
    }

    /* FNV-1a, 32 bit, hex — short enough to ride along in a hidden field. */
    function _hash(text) {
        var h = 0x811c9dc5;
        for (var i = 0; i < text.length; i++) {
            h ^= text.charCodeAt(i);
            h = Math.imul(h, 0x01000193) >>> 0;
        }
        return ('0000000' + h.toString(16)).slice(-8);
    }

    function _update(form) {
        if (form._z77DirtyBase === undefined) return;
        var dirty = form._z77DirtySticky || _hash(_snapshot(form)) !== form._z77DirtyBase;
        form.setAttribute('data-dirty', dirty ? 'dirty' : 'clean');
        form.querySelectorAll('[data-dirty-submit]').forEach(function (b) {
            b.setAttribute('aria-disabled', dirty ? 'false' : 'true');
        });
    }

    /* Starts tracking every form[data-dirty] in container (wire() calls it for new content). */
    function track(container) {
        var forms = Array.prototype.slice.call(container.querySelectorAll('form[data-dirty]'));
        if (container.matches && container.matches('form[data-dirty]')) forms.push(container);
        forms.forEach(function (form) {
            if (form._z77DirtyBase !== undefined) return;
            form._z77DirtySticky = form.getAttribute('data-dirty') === 'dirty';
            // The state the form was LOADED with travels through a server round trip that does
            // not save («+ Weitere Zeilen», «anpassen», errors): the hidden `_dirty_base` keeps
            // its hash, the server hands it back, and the comparison stays with the saved state.
            var carry = form.querySelector('input[name="_dirty_base"]');
            form._z77DirtyBase = carry && /^[0-9a-f]{8}$/.test(carry.value) ? carry.value : _hash(_snapshot(form));
            if (carry) carry.value = form._z77DirtyBase;
            if (window.MutationObserver) {
                new MutationObserver(function () { _update(form); }).observe(form, { childList: true, subtree: true });
            }
            _update(form);
        });
    }

    /* Whether scope holds a form with unsaved changes. */
    function isDirty(scope) {
        return !!(scope && scope.querySelector('form[data-dirty="dirty"]'));
    }

    function bind() {
        ['input', 'change'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                var form = e.target.form || (e.target.closest && e.target.closest('form[data-dirty]'));
                if (form) _update(form);
            });
        });
        // Capture phase: a clean save never reaches the fetch submit of a window or region.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form.getAttribute && form.getAttribute('data-dirty') === 'clean'
                && e.submitter && e.submitter.hasAttribute('data-dirty-submit')) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, true);
        track(document);
    }

    return { bind: bind, track: track, isDirty: isDirty };
})();

/* ── rows ───────────────────────────────────────────────────────────────────
 * Form rows the user adds, removes and puts in order (first user: the positions of an order,
 * owner 2026-10-18). Adding and removing stay SUBMITS for a page without the script — the server
 * answers with the changed rows; the script does the same in place. A new row is a clone of a
 * server-rendered <template>, never markup built from data (Rule 7). The server judges the rows
 * it receives; the order and the indent are only what the user arranged.
 *
 * Contract:
 *   [data-rows="<prefix>"]        the container; its DIRECT children [data-row] are the rows. After
 *                                 every change the field names `<prefix>[<n>][…]` are renumbered
 *                                 0…n-1 in DOM order (the server reads the rows in posted order)
 *   [data-row]                    one row
 *     [data-row-grip]             a button: drag it (pointer) or press Alt+↑ / Alt+↓ on it to move
 *                                 the row. Render it `hidden` — the script reveals it
 *     [data-row-indent]           a checkbox: checked = the row is indented beneath the nearest
 *                                 row above that is not; a row that is not indented moves together
 *                                 with the indented rows below it
 *     [data-row-no]               the position number: «1», «1.1» (textContent)
 *     [data-row-remove]           a button (a submit without the script): removes its row
 *   template[data-row-template="<kind>"]  inside the container: the row to clone; `__i__` in the
 *                                 name / id / for / href / data-window-open attributes of the clone
 *                                 becomes a unique key (`n1`, `n2` …)
 *   [data-row-add="<kind>"]       a button of the same form (a submit without the script): appends
 *                                 a clone of that template and focuses its first field — so does the
 *                                 command `add-row` (a choice in another window, «fetch» commands)
 * After every change the container fires a bubbling `input` event: «dirty forms» and «line sums»
 * follow — a move IS a change (the names carry the index), moved back the form is clean again.
 * Rows being dragged carry `data-row-dragging`. A control in a disabled fieldset does nothing.
 */
_Z77.core.rows = (function () {
    var _seq = 0;

    function _rows(box) {
        return Array.prototype.filter.call(box.children, function (el) { return el.hasAttribute('data-row'); });
    }

    function _indented(row) {
        var flag = row.querySelector('[data-row-indent]');
        return !!(flag && flag.checked);
    }

    /* What moves with rows[i]: the row itself and — when it is not indented — the indented rows below. */
    function _unit(rows, i) {
        var unit = [rows[i]];
        if (_indented(rows[i])) return unit;
        for (var j = i + 1; j < rows.length && _indented(rows[j]); j++) unit.push(rows[j]);
        return unit;
    }

    /* What the unit at i passes going up: the row above (an indented row moves alone), else the whole unit above. */
    function _above(rows, i) {
        if (i === 0) return [];
        if (_indented(rows[i])) return [rows[i - 1]];
        var j = i - 1;
        while (j > 0 && _indented(rows[j])) j--;
        return rows.slice(j, i);
    }

    function _below(rows, i) {
        var next = i + _unit(rows, i).length;
        if (next >= rows.length) return [];
        return _indented(rows[i]) ? [rows[next]] : _unit(rows, next);
    }

    /* A row of a subgrid list is `display: contents` — no box of its own; its children have one. */
    function _middle(unit) {
        var top = Infinity, bottom = -Infinity;
        unit.forEach(function (row) {
            [row].concat(Array.prototype.slice.call(row.children)).forEach(function (el) {
                var b = el.getBoundingClientRect();
                if (b.height) { top = Math.min(top, b.top); bottom = Math.max(bottom, b.bottom); }
            });
        });
        return (top + bottom) / 2;
    }

    /* One step up (-1) or down (+1). The NEIGHBOUR changes sides, so the moved rows stay in the
     * document — pointer capture and focus survive. True when something moved. */
    function _step(row, dir) {
        var box = row.parentNode, rows = _rows(box), i = rows.indexOf(row);
        var unit = _unit(rows, i);
        if (dir < 0) {
            var above = _above(rows, i), after = unit[unit.length - 1].nextSibling;
            above.forEach(function (el) { box.insertBefore(el, after); });
            return above.length > 0;
        }
        var below = _below(rows, i);
        below.forEach(function (el) { box.insertBefore(el, unit[0]); });
        return below.length > 0;
    }

    function _renumber(box) {
        var prefix = box.getAttribute('data-rows');
        var pattern = new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]]*\\]');
        var main = 0, sub = 0;
        _rows(box).forEach(function (row, n) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(pattern, prefix + '[' + n + ']');
            });
            // As the server prints it: an indented row counts under its parent; one without a parent counts as a row.
            if (_indented(row) && main > 0) { sub++; } else { main++; sub = 0; }
            var no = row.querySelector('[data-row-no]');
            if (no) no.textContent = sub ? main + '.' + sub : String(main);
        });
    }

    function _changed(box) {
        _renumber(box);
        box.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function _add(button) {
        var form = button.form || button.closest('form');
        var tpl = form && form.querySelector('template[data-row-template="' + button.getAttribute('data-row-add') + '"]');
        var box = tpl && tpl.closest('[data-rows]');
        return !!(box && add(box, button.getAttribute('data-row-add')));
    }

    /* Appends a clone of box's template `kind`, focuses its first field; returns the row (null
     * without such a template). Also the `add-row` command's way in. */
    function add(box, kind) {
        var tpl = box && box.querySelector('template[data-row-template="' + kind + '"]');
        if (!tpl || tpl.closest('[data-rows]') !== box || !tpl.content.firstElementChild) return null;
        var row = tpl.content.firstElementChild.cloneNode(true);
        var key = 'n' + (++_seq);
        [row].concat(Array.prototype.slice.call(row.querySelectorAll('*'))).forEach(function (el) {
            ['name', 'id', 'for', 'href', 'data-window-open'].forEach(function (attr) {
                var value = el.getAttribute(attr);
                if (value && value.indexOf('__i__') !== -1) el.setAttribute(attr, value.split('__i__').join(key));
            });
        });
        box.appendChild(row);
        init(row);
        _Z77.core.autosize.init(row);
        _changed(box);
        var first = row.querySelector('input:not([type="hidden"]):not([type="checkbox"]), textarea, select');
        if (first) first.focus();
        return row;
    }

    function _remove(button) {
        var row = button.closest('[data-row]');
        var box = row && row.parentNode;
        if (!box || !box.hasAttribute('data-rows')) return false;
        var rows = _rows(box), i = rows.indexOf(row);
        var next = rows[i + 1] || rows[i - 1];
        box.removeChild(row);
        _changed(box);
        var focus = next && next.querySelector('[data-row-remove]');
        if (focus) focus.focus();
        return true;
    }

    function _drag(grip, e) {
        var row = grip.closest('[data-row]');
        var box = row && row.parentNode;
        if (!box || !box.hasAttribute('data-rows')) return;
        var moved = false;
        var mark = function (on) {
            var rows = _rows(box);
            _unit(rows, rows.indexOf(row)).forEach(function (el) { el.toggleAttribute('data-row-dragging', on); });
        };
        var move = function (ev) {
            var rows = _rows(box), i = rows.indexOf(row);
            var above = _above(rows, i), below = _below(rows, i);
            if (above.length && ev.clientY < _middle(above)) moved = _step(row, -1) || moved;
            else if (below.length && ev.clientY > _middle(below)) moved = _step(row, 1) || moved;
        };
        var end = function () {
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', end);
            grip.removeEventListener('pointercancel', end);
            mark(false);
            if (moved) _changed(box);
        };
        grip.setPointerCapture(e.pointerId);
        mark(true);
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', end);
        grip.addEventListener('pointercancel', end);
    }

    /* Reveals the grips (rendered `hidden`, so a page without the script shows no dead control). */
    function init(container) {
        var grips = container.querySelectorAll('[data-row-grip][hidden]');
        for (var i = 0; i < grips.length; i++) grips[i].hidden = false;
        if (container.matches && container.matches('[data-row-grip][hidden]')) container.hidden = false;
    }

    function bind() {
        document.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            var add = e.target.closest('[data-row-add]');
            var remove = add ? null : e.target.closest('[data-row-remove]');
            var button = add || remove;
            if (!button || button.matches(':disabled')) return;
            if (add ? _add(add) : _remove(remove)) e.preventDefault();   // done here — no submit
        });
        document.addEventListener('pointerdown', function (e) {
            var grip = e.target.closest && e.target.closest('[data-row-grip]');
            if (!grip || grip.matches(':disabled') || e.button !== 0) return;
            e.preventDefault();
            _drag(grip, e);
        });
        document.addEventListener('keydown', function (e) {
            if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
            var grip = e.target.closest && e.target.closest('[data-row-grip]');
            var row = grip && !grip.matches(':disabled') ? grip.closest('[data-row]') : null;
            if (!row || !row.parentNode.hasAttribute('data-rows')) return;
            e.preventDefault();
            if (_step(row, e.key === 'ArrowUp' ? -1 : 1)) { _changed(row.parentNode); grip.focus(); }
        });
        document.addEventListener('change', function (e) {
            var box = e.target.matches && e.target.matches('[data-row-indent]') ? e.target.closest('[data-rows]') : null;
            if (box) _renumber(box);
        });
        init(document);
    }

    return { bind: bind, init: init, add: add };
})();

/* ── autosize ───────────────────────────────────────────────────────────────
 * A text field as tall as its text (first user: the order line's text, whose article fill brings
 * the name and a description below it, 2026-10-18). Looks only — nothing is sent or decided.
 *
 * Contract:
 *   textarea[data-autosize]          grows and shrinks with its text, no scrollbar. The server sets
 *                                    `rows` to the number of lines, so it fits without the script;
 *                                    CSS `field-sizing: content` does it alone where it exists
 *   textarea[data-autosize="title"]  shows its FIRST line as a title: a mirror lies over the field
 *                                    (`[data-autosize-mirror]`, aria-hidden, the field's classes) and
 *                                    paints the text — the first line in `[data-autosize-title]`, the
 *                                    rest after it; the field's parent gets `[data-autosize-on]`, CSS
 *                                    turns the field's own ink transparent. Caret, selection and
 *                                    input stay the field's. The mirror's text is set by textContent.
 */
_Z77.core.autosize = (function () {
    var _native = !!(window.CSS && CSS.supports && CSS.supports('field-sizing', 'content'));

    function _size(el) {
        if (_native || !el.isConnected || !el.offsetParent) return;   // hidden: sized when typed in
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + el.offsetHeight - el.clientHeight) + 'px';
    }

    function _mirror(el) {
        var host = el.parentNode;
        var mirror = host && host.querySelector('[data-autosize-mirror]');
        if (!host) return;
        if (!mirror) {
            mirror = document.createElement('span');
            mirror.className = el.className;   // the field's box: padding, border width, font
            mirror.setAttribute('data-autosize-mirror', '');
            mirror.setAttribute('aria-hidden', 'true');
            mirror.appendChild(document.createElement('span')).setAttribute('data-autosize-title', '');
            mirror.appendChild(document.createTextNode(''));
            host.appendChild(mirror);
            host.setAttribute('data-autosize-on', '');
        }
        var text = el.value, cut = text.indexOf('\n');
        mirror.firstChild.textContent = cut === -1 ? text : text.slice(0, cut + 1);
        // A trailing line break needs a character after it to take its line.
        mirror.lastChild.textContent = (cut === -1 ? '' : text.slice(cut + 1)) + (/\n$/.test(text) ? ' ' : '');
        mirror.scrollTop = el.scrollTop;
    }

    function _update(el) {
        if (el.getAttribute('data-autosize') === 'title') _mirror(el);
        _size(el);
    }

    /* Wires the fields in container (also a fragment not yet in the page — sized a frame later). */
    function init(container) {
        var list = Array.prototype.slice.call(container.querySelectorAll('textarea[data-autosize]'));
        if (container.matches && container.matches('textarea[data-autosize]')) list.push(container);
        list.forEach(function (el) { if (el.getAttribute('data-autosize') === 'title') _mirror(el); });
        if (list.length) window.requestAnimationFrame(function () { list.forEach(_size); });
    }

    function bind() {
        ['input', 'focusin'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                if (e.target.matches && e.target.matches('textarea[data-autosize]')) _update(e.target);
            });
        });
        // A field that scrolls after all (narrowed, not sized yet) keeps its mirror in step.
        document.addEventListener('scroll', function (e) {
            var el = e.target;
            if (el.matches && el.matches('textarea[data-autosize="title"]')) _mirror(el);
        }, true);
        init(document);
    }

    return { bind: bind, init: init };
})();

/* ── line sums ──────────────────────────────────────────────────────────────
 * A live total of document lines while they are typed (first user: the order's positions, owner
 * 2026-10-18). DISPLAY ONLY: the stored total is the server's (debtor's preview, D11) — this
 * shows what the rows add up to now, without the rounding, until the save answers. Nothing is
 * sent (Rule 7).
 *
 * Contract:
 *   [data-sum]                    the scope; recomputed on every input / change inside it
 *     [data-sum-mode]             optional select: 'gross' = the prices include VAT
 *     [data-row]                  a line — it counts with a [data-sum-qty] and a [data-sum-price]:
 *       [data-sum-qty] [data-sum-price] [data-sum-discount]  fields (the discount in percent)
 *       [data-sum-rate]           the VAT rate in percent: `data-rate` of the selected <option> of
 *                                 a select, else of the element itself; none = no VAT
 *       [data-sum-amount]         the line amount (textContent), empty while a value is no number
 *     [data-sum-net] [data-sum-gross]  the totals (textContent)
 *     [data-sum-taxes]            emptied and refilled with clones of its
 *       template[data-sum-tax]    whose [data-sum-tax-rate] [data-sum-tax-base] [data-sum-tax-amount]
 *                                 get the numbers — one per rate, in the order the rates appear
 *     [data-sum-stored]           hidden once the sum is live (the server's alone: the rounding)
 *     [data-sum-live]             shown once the sum is live (the label saying so)
 * Numbers read Swiss style («1'234.50», a comma as the point) and print with «'» and two decimals.
 */
_Z77.core.sums = (function () {
    function _num(value) {
        var v = String(value || '').trim().replace(/['’\s]/g, '').replace(',', '.');
        return /^-?(\d+\.?\d*|\.\d+)$/.test(v) ? parseFloat(v) : null;
    }

    function _round(n) { return Math.round(n * 100) / 100; }

    function _fmt(n) {
        var s = _round(n).toFixed(2), sign = '';
        if (s.charAt(0) === '-') { sign = s === '-0.00' ? '' : '-'; s = s.slice(1); }
        var parts = s.split('.');
        return sign + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'") + '.' + parts[1];
    }

    function _rate(el) {
        if (!el) return '';
        var source = el.tagName === 'SELECT' ? el.options[el.selectedIndex] : el;
        return (source && source.getAttribute('data-rate')) || '';
    }

    function _set(scope, selector, text) {
        scope.querySelectorAll(selector).forEach(function (el) { el.textContent = text; });
    }

    function update(scope) {
        var modeField = scope.querySelector('[data-sum-mode]');
        var gross = !!(modeField && modeField.value === 'gross');
        var rates = [], sums = {};
        scope.querySelectorAll('[data-row]').forEach(function (row) {
            var qty = row.querySelector('[data-sum-qty]'), price = row.querySelector('[data-sum-price]');
            if (!qty || !price) return;
            var q = _num(qty.value), p = _num(price.value);
            var discountField = row.querySelector('[data-sum-discount]');
            var d = discountField && discountField.value.trim() !== '' ? _num(discountField.value) : 0;
            var out = row.querySelector('[data-sum-amount]');
            if (q === null || p === null || d === null) { if (out) out.textContent = ''; return; }
            var amount = _round(q * p * (1 - d / 100));
            if (out) out.textContent = _fmt(amount);
            var rate = _rate(row.querySelector('[data-sum-rate]'));
            if (!(rate in sums)) { sums[rate] = 0; rates.push(rate); }
            sums[rate] += amount;
        });
        var net = 0, tax = 0, lines = [];
        rates.forEach(function (rate) {
            var r = (_num(rate) || 0) / 100;
            var base = gross ? _round(sums[rate] / (1 + r)) : _round(sums[rate]);
            var vat = gross ? _round(sums[rate] - base) : _round(base * r);
            net += base;
            tax += vat;
            if (rate !== '') lines.push([rate, base, vat]);
        });
        _set(scope, '[data-sum-net]', _fmt(net));
        _set(scope, '[data-sum-gross]', _fmt(net + tax));
        var taxes = scope.querySelector('[data-sum-taxes]');
        var tpl = taxes && taxes.querySelector('template[data-sum-tax]');
        if (tpl) {
            Array.prototype.slice.call(taxes.children).forEach(function (el) { if (el !== tpl) taxes.removeChild(el); });
            lines.forEach(function (line) {
                var el = tpl.content.firstElementChild.cloneNode(true);
                _set(el, '[data-sum-tax-rate]', line[0]);
                _set(el, '[data-sum-tax-base]', _fmt(line[1]));
                _set(el, '[data-sum-tax-amount]', _fmt(line[2]));
                taxes.appendChild(el);
            });
        }
        scope.querySelectorAll('[data-sum-stored]').forEach(function (el) { el.hidden = true; });
        scope.querySelectorAll('[data-sum-live]').forEach(function (el) { el.hidden = false; });
    }

    function bind() {
        ['input', 'change'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                var scope = e.target.closest && e.target.closest('[data-sum]');
                if (scope) update(scope);
            });
        });
    }

    return { bind: bind, update: update };
})();

/* ── dated rates ────────────────────────────────────────────────────────────
 * Options whose RATE depends on a date field of the form (first user: the order's VAT per row —
 * the SERVICE date decides the rate, owner 2026-10-18). The server renders the rates for the date
 * it knew; when the date changes, the options are re-labelled from the schedule at once, and
 * «line sums» / «price mode» read the new `data-rate`. Display only — the save resolves the rate
 * itself (Rule 7).
 *
 * Contract:
 *   script[type="application/json"][data-rate-schedule="<name>"]
 *                                 inside a form: { option value: [[valid from 'Y-m-d', rate], …] }
 *                                 oldest first; `data-rate-date="a b"` = the form fields that hold
 *                                 the date, the first non-empty one counts
 *   select[data-rate-schedule-ref="<name>"]  its options get `data-rate` and the label «<rate> %»
 *                                 for that date (no rate in effect: the value as label, no rate)
 *     [data-rate-keep-label]      optional: only `data-rate` follows, the labels stay (a CODE picker —
 *                                 the invoice editor's MWST code, owner 2026-10-19)
 * Re-applied when a date field changes and when rows are added ([data-rows] fires `input`).
 */
_Z77.core.datedRates = (function () {
    function _at(list, date) {
        var hit = null;
        (list || []).forEach(function (entry) { if (entry[0] <= date) hit = entry[1]; });
        return hit;
    }

    function apply(island) {
        var form = island.closest('form');
        if (!form) return;
        var schedule;
        try { schedule = JSON.parse(island.textContent) || {}; } catch (e) { return; }
        var date = '';
        (island.getAttribute('data-rate-date') || '').split(/\s+/).some(function (name) {
            var field = name ? form.elements.namedItem(name) : null;
            date = field && typeof field.value === 'string' ? field.value.trim() : '';
            return date !== '';
        });
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return;
        var name = island.getAttribute('data-rate-schedule');
        form.querySelectorAll('select[data-rate-schedule-ref="' + name + '"]').forEach(function (select) {
            var before = select.selectedIndex >= 0 ? select.options[select.selectedIndex].getAttribute('data-rate') : null;
            var keep = select.hasAttribute('data-rate-keep-label');
            Array.prototype.forEach.call(select.options, function (option) {
                if (!option.value || !Object.prototype.hasOwnProperty.call(schedule, option.value)) return;
                var rate = _at(schedule[option.value], date);
                if (rate === null) { option.removeAttribute('data-rate'); if (!keep) option.textContent = option.value; }
                else { option.setAttribute('data-rate', rate); if (!keep) option.textContent = rate + ' %'; }
            });
            var after = select.selectedIndex >= 0 ? select.options[select.selectedIndex].getAttribute('data-rate') : null;
            if (after !== before) select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function _islandsFor(target) {
        var form = target.form || (target.closest && target.closest('form'));
        if (!form) return [];
        return Array.prototype.filter.call(form.querySelectorAll('script[data-rate-schedule]'), function (island) {
            if (target.hasAttribute && target.hasAttribute('data-rows')) return true;
            return target.name && (' ' + (island.getAttribute('data-rate-date') || '') + ' ').indexOf(' ' + target.name + ' ') !== -1;
        });
    }

    function bind() {
        ['change', 'input'].forEach(function (type) {
            document.addEventListener(type, function (e) {
                if (e.target.tagName === 'SELECT' && e.target.hasAttribute('data-rate-schedule-ref')) return;   // our own echo
                _islandsFor(e.target).forEach(apply);
            });
        });
    }

    return { bind: bind, apply: apply };
})();

/* ── price mode ─────────────────────────────────────────────────────────────
 * Prices that follow a change of the price mode (owner 2026-10-18 — before, an article price
 * filled under «netto» kept its number under «brutto»: an article of 16.65 gross was billed
 * 15.40 incl. VAT). Users: the order window, debtor's invoice editor (2026-10-19 — no article
 * source, every price converts with its row's rate). A change converts every row IN PLACE at once, without a question. The script
 * only helps: the SERVER converts again at the save whenever the posted prices are in another
 * mode than the select (no script, a stale client — Rule 7); the hidden «shown» field tells it.
 *
 * Contract:
 *   [data-price-mode]                 the scope (the rows with their price-mode select)
 *     select[data-price-mode-select]  'net' | 'gross' — a change converts the rows
 *     input[data-price-mode-shown]    hidden and NAMED (posted): the mode the prices are in —
 *                                     rendered by the server, written here once a change is done
 *     [data-row]                      a line:
 *       [data-price-mode-price]       its price field (empty or not an amount: left as it is)
 *       [data-price-mode-rate]        the VAT rate in percent: `data-rate` of the selected
 *                                     <option> of a select, else of the element itself
 *       [data-fill-url]               optional, the row's «fill» source: with a value it is asked
 *                                     (`prices=1`); `data.prices` = { net, gross } are the exact
 *                                     prices — a price EQUAL to the old mode's becomes the new
 *                                     mode's (an article: 16.65 gross, not 15.40 × 1.081)
 * Any other price (typed by hand, no source, no answer) is converted with its row's rate — in
 * cents, r = the rate in hundredths of a percent, rounded half away from zero, the server's rule
 * (module-vat `VatCalculator::convertPrice()`): net → gross c × (10000 + r) / 10000, gross → net
 * c × 10000 / (10000 + r). A row without a rate keeps its price. The rows of one change are
 * written together, with the shown mode, when every answer is in — never half converted; a price
 * typed while the answers were on their way is left as typed. Changes are worked off in turn.
 * Every written field fires `input` («line sums», «dirty forms» follow).
 */
_Z77.core.priceMode = (function () {
    var MODES = { net: 1, gross: 1 };
    var _queue = Promise.resolve();

    /* «16.65», «1'234.5», «-3,20» → cents; null for anything that is no amount of two decimals. */
    function _cents(value) {
        var v = String(value == null ? '' : value).trim().replace(/['’\s]/g, '').replace(',', '.');
        var m = /^(-?)(\d*)(?:\.(\d{0,2}))?$/.exec(v);
        if (!m || (m[2] === '' && !m[3])) return null;
        var c = parseInt(m[2] || '0', 10) * 100 + parseInt(((m[3] || '') + '00').slice(0, 2), 10);
        return m[1] === '-' ? -c : c;
    }

    function _decimal(c) {
        var a = Math.abs(c);
        return (c < 0 ? '-' : '') + Math.floor(a / 100) + '.' + ('0' + (a % 100)).slice(-2);
    }

    /* num / den, rounded half away from zero (den > 0). */
    function _divide(num, den) {
        var a = Math.abs(num), q = Math.floor(a / den);
        if (2 * (a - q * den) >= den) q++;
        return num < 0 ? -q : q;
    }

    /* The rate in hundredths of a percent («8.1» → 810), null without one. */
    function _rate(el) {
        if (!el) return null;
        var source = el.tagName === 'SELECT' ? el.options[el.selectedIndex] : el;
        var v = parseFloat(String((source && source.getAttribute('data-rate')) || '').replace(',', '.'));
        return isNaN(v) ? null : Math.round(v * 100);
    }

    function _convert(scope) {
        var select = scope.querySelector('[data-price-mode-select]');
        var shown = scope.querySelector('[data-price-mode-shown]');
        if (!select || !shown) return Promise.resolve();
        var from = shown.value, to = select.value;
        if (from === to || !MODES[from] || !MODES[to]) return Promise.resolve();
        var jobs = [];
        scope.querySelectorAll('[data-row]').forEach(function (row) {
            var price = row.querySelector('[data-price-mode-price]');
            var c = price ? _cents(price.value) : null;
            if (c === null) return;
            var before = price.value;
            var r = _rate(row.querySelector('[data-price-mode-rate]'));
            var byRate = r === null ? null : (to === 'gross' ? _divide(c * (10000 + r), 10000) : _divide(c * 10000, 10000 + r));
            var source = row.querySelector('[data-fill-url]');
            var asked = source && source.value.trim() !== '' && _Z77.core.fill
                ? _Z77.core.fill.ask(source, { prices: '1' }).catch(function () { return null; })
                : Promise.resolve(null);
            jobs.push(asked.then(function (env) {
                var prices = env && env.status === 'success' && env.data ? env.data.prices : null;
                var exact = prices && _cents(prices[from]) === c ? _cents(prices[to]) : null;
                return { field: price, before: before, next: exact !== null ? exact : byRate };
            }));
        });
        return Promise.all(jobs).then(function (results) {
            results.forEach(function (res) {
                if (res.next === null || res.field.value !== res.before) return;
                res.field.value = _decimal(res.next);
                res.field.dispatchEvent(new Event('input', { bubbles: true }));
            });
            shown.value = to;
            shown.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function bind() {
        document.addEventListener('change', function (e) {
            if (!e.target.matches || !e.target.matches('select[data-price-mode-select]')) return;
            var scope = e.target.closest('[data-price-mode]');
            if (!scope) return;
            _queue = _queue.then(function () { return _convert(scope); }).catch(function () {});
        });
    }

    return { bind: bind };
})();

/* ── tree picker ────────────────────────────────────────────────────────────
 * A FILTER over a long tree as a field (first user: the product group of the article list,
 * owner 2026-10-18 — a few hundred groups on up to four levels, which a flat list of links
 * cannot carry). The server renders `Z77\Shared` `partials/treePicker`; without this script it
 * is a <details> whose panel lists every node as an indented link — it works as it is. The
 * script replaces that list by a popover: a search over ALL levels and ONE level at a time.
 * Rule 7: every row is a LINK to the address the server built; the server validates the
 * filter. Nothing here decides anything — it only shows the same links another way.
 *
 * Contract:
 *   [data-tree-picker]                 the root; holds the <details> (its <summary> is the field)
 *   [data-tree-picker-fallback]        inside the <details>: the list of links the script replaces
 *   script[type="application/json"][data-tree-picker-data]   the data island (never HTML):
 *       { value: id|null,
 *         all:   { label, href, count? },
 *         nodes: [{ id, parent: id|null, name, count?, note?, href }]   — in tree order,
 *         text:  { search, top, allIn: "…%s…", open, hits: "%d …", max: "%d …", none, level: "%d …", foot } }
 * The root gets [data-tree-picker-on] once enhanced. The popover opens at the level of the
 * chosen node; the path line leads back. A row: the name (a link — the filter), the count, «›»
 * (its children; disabled without). Typing searches every level: each word must appear in the
 * node's path and at least one in its own name; own-name hits first, then shallow first, at
 * most 50; matches are <mark> elements. Keys in the search: ↑/↓ move, → opens, ← goes up,
 * Enter follows the active row's link, Esc closes. A click outside closes — a click on an
 * element a re-render just removed (›, the path) is NOT outside (`isConnected`).
 * Inside a fetch region (the article picker's window, 2026-10-19) the links carry
 * `data-fetch-region-link`: a choice reloads only that region (Enter clicks the link, so it
 * takes the same way).
 * Rows are built with createElement / textContent only.
 */
_Z77.core.treePicker = (function () {
    var LIMIT = 50;

    function _key(id) { return id === null || id === undefined ? '' : String(id); }

    /* Pure: nodes → { nodes, byId, kids (parent key → children, '' = roots), path (key → nodes, root first) }. */
    function index(nodes) {
        var idx = { nodes: nodes || [], byId: {}, kids: {}, path: {} };
        idx.nodes.forEach(function (n) { idx.byId[_key(n.id)] = n; });
        idx.nodes.forEach(function (n) {
            var parent = idx.byId[_key(n.parent)] ? _key(n.parent) : '';
            (idx.kids[parent] = idx.kids[parent] || []).push(n);
        });
        idx.nodes.forEach(function (n) {
            var chain = [];
            for (var x = n, guard = 64; x && guard-- > 0; x = idx.byId[_key(x.parent)] || null) chain.unshift(x);
            idx.path[_key(n.id)] = chain;
        });
        return idx;
    }

    function _words(query) {
        return String(query || '').toLocaleLowerCase().split(/\s+/).filter(Boolean);
    }

    /* Pure: the hits of query over every level → { nodes (at most limit), total }. */
    function search(idx, query, limit) {
        var words = _words(query), hits = [];
        if (!words.length) return { nodes: [], total: 0 };
        idx.nodes.forEach(function (n) {
            var chain = idx.path[_key(n.id)] || [n];
            var full = chain.map(function (x) { return x.name; }).join(' ').toLocaleLowerCase();
            var name = String(n.name).toLocaleLowerCase();
            if (!words.every(function (w) { return full.indexOf(w) !== -1; })) return;
            var own = words.filter(function (w) { return name.indexOf(w) !== -1; }).length;
            if (!own) return;
            hits.push({ node: n, rank: own === words.length ? 0 : 1, depth: chain.length });
        });
        hits.sort(function (a, b) {
            return a.rank - b.rank || a.depth - b.depth || String(a.node.name).localeCompare(String(b.node.name), 'de');
        });
        return { nodes: hits.slice(0, limit || LIMIT).map(function (h) { return h.node; }), total: hits.length };
    }

    /* Pure: where the words of query lie in name → sorted, non-overlapping [start, end] pairs. */
    function marks(name, query) {
        var lower = String(name).toLocaleLowerCase(), ranges = [], out = [], end = 0;
        _words(query).forEach(function (w) {
            var at = lower.indexOf(w);
            if (at !== -1) ranges.push([at, at + w.length]);
        });
        ranges.sort(function (a, b) { return a[0] - b[0] || b[1] - a[1]; });
        ranges.forEach(function (r) { if (r[0] >= end) { out.push(r); end = r[1]; } });
        return out;
    }

    function _fmt(text, value) { return String(text || '').replace(/%[sd]/, value); }

    function _el(tag, cls, text) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        if (text !== undefined && text !== null) el.textContent = text;
        return el;
    }

    function _enhance(root) {
        var island = root.querySelector('script[type="application/json"][data-tree-picker-data]');
        var details = root.querySelector('details');
        var fallback = root.querySelector('[data-tree-picker-fallback]');
        var data;
        if (!island || !details || !fallback) return;
        try { data = JSON.parse(island.textContent); } catch (e) { return; }
        if (!data || !Array.isArray(data.nodes) || !data.all) return;

        var text = data.text || {};
        var inRegion = !!root.closest('[data-fetch-region]');
        var idx = index(data.nodes);
        var chosen = idx.byId[_key(data.value)] || null;
        var summary = details.querySelector('summary');
        var pop = _el('div', 'be-tree-picker__panel be-tree-picker__pop');
        var find = _el('div', 'be-tree-picker__search');
        var input = _el('input');
        var trail = _el('div', 'be-tree-picker__path');
        var list = _el('div', 'be-tree-picker__list');
        var foot = _el('div', 'be-tree-picker__foot');
        var info = _el('span');
        var browse = '';      // the level shown: the parent's key, '' = the top
        var items = [];       // the rows shown: [{ node|null, own }]
        var active = 0;

        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = text.search || '';
        input.setAttribute('aria-label', text.search || '');
        find.appendChild(input);
        foot.appendChild(info);
        if (text.foot) foot.appendChild(_el('span', null, text.foot));
        pop.appendChild(find);
        pop.appendChild(trail);
        pop.appendChild(list);
        pop.appendChild(foot);
        fallback.replaceWith(pop);
        root.setAttribute('data-tree-picker-on', '');

        function hasKids(node) { return !!(node && idx.kids[_key(node.id)]); }

        function level(key) {
            browse = key;
            active = 0;
            input.value = '';
            render();
            input.focus();
        }

        function step(label, key) {
            var b = _el('button', 'be-tree-picker__step', label);
            b.type = 'button';
            b.addEventListener('click', function () { level(key); });
            return b;
        }

        function name(node, query) {
            var span = _el('span', 'be-tree-picker__name'), at = 0, label = String(node.name);
            marks(label, query).forEach(function (r) {
                span.appendChild(document.createTextNode(label.slice(at, r[0])));
                span.appendChild(_el('mark', null, label.slice(r[0], r[1])));
                at = r[1];
            });
            span.appendChild(document.createTextNode(label.slice(at)));
            if (node.note) span.appendChild(_el('span', 'be-tree-picker__note', ' (' + node.note + ')'));
            return span;
        }

        function row(item, i, query) {
            var node = item.node;
            var line = _el('div', 'be-tree-picker__row');
            var pick = _el('a', 'be-tree-picker__pick');
            var into = _el('button', 'be-tree-picker__in', '›');
            var count = node ? node.count : data.all.count;
            if (i === active) line.setAttribute('data-active', '');
            pick.href = node ? node.href : data.all.href;
            if (inRegion) pick.setAttribute('data-fetch-region-link', '');
            if (node === chosen) pick.setAttribute('aria-current', 'true');
            if (!node) pick.appendChild(_el('span', 'be-tree-picker__name', data.all.label));
            else if (item.own) pick.appendChild(_el('span', 'be-tree-picker__name', _fmt(text.allIn, node.name)));
            else pick.appendChild(name(node, query));
            if (query && node && idx.path[_key(node.id)].length > 1) {
                var above = idx.path[_key(node.id)].slice(0, -1).map(function (x) { return x.name; });
                pick.appendChild(_el('span', 'be-tree-picker__where', above.join(' › ')));
            }
            line.appendChild(pick);
            line.appendChild(_el('span', 'be-tree-picker__count', count === undefined || count === null ? '' : String(count)));
            into.type = 'button';
            into.title = text.open || '';
            into.setAttribute('aria-label', (text.open || '') + (node ? ': ' + node.name : ''));
            into.disabled = item.own || !hasKids(node);
            into.addEventListener('click', function () { level(_key(node.id)); });
            line.appendChild(into);
            return line;
        }

        function render() {
            var query = input.value.trim();
            list.replaceChildren();
            trail.replaceChildren();
            if (query) {
                var found = search(idx, query, LIMIT);
                items = found.nodes.map(function (n) { return { node: n, own: false }; });
                trail.appendChild(_el('span', null, !found.total ? text.none : found.total > LIMIT ? _fmt(text.max, LIMIT) : _fmt(text.hits, found.total)));
                info.textContent = '';
            } else {
                var here = browse !== '' ? idx.byId[browse] || null : null;
                var chain = here ? idx.path[browse] : [];
                var kids = idx.kids[here ? browse : ''] || [];
                if (!chain.length) trail.appendChild(_el('span', 'be-tree-picker__here', data.all.label));
                else trail.appendChild(step(text.top || data.all.label, ''));
                chain.forEach(function (n, i) {
                    trail.appendChild(_el('span', 'be-tree-picker__sep', '›'));
                    trail.appendChild(i === chain.length - 1 ? _el('span', 'be-tree-picker__here', n.name) : step(n.name, _key(n.id)));
                });
                items = [{ node: here, own: !!here }].concat(kids.map(function (n) { return { node: n, own: false }; }));
                info.textContent = _fmt(text.level, kids.length);
            }
            active = Math.max(0, Math.min(active, items.length - 1));
            items.forEach(function (item, i) { list.appendChild(row(item, i, query)); });
            var current = list.children[active];
            if (current && typeof current.scrollIntoView === 'function') current.scrollIntoView({ block: 'nearest' });
        }

        function open() {
            browse = chosen && idx.byId[_key(chosen.parent)] ? _key(chosen.parent) : '';
            input.value = '';
            active = 0;
            render();
            items.forEach(function (item, i) { if (chosen && item.node === chosen && !item.own) active = i; });
            if (active) render();
            input.focus();
        }

        function close() {
            details.open = false;
            if (summary) summary.focus();
        }

        details.addEventListener('toggle', function () { if (details.open) open(); });
        input.addEventListener('input', function () { active = 0; render(); });
        input.addEventListener('keydown', function (e) {
            var query = input.value.trim(), item = items[active];
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                active = Math.max(0, Math.min(items.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)));
                render();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var link = list.children[active] && list.children[active].querySelector('a');
                if (link) link.click();   // the link's own way: a page load, or the region's reload
            } else if (e.key === 'ArrowRight' && !query && item && !item.own && hasKids(item.node)) {
                e.preventDefault();
                level(_key(item.node.id));
            } else if (e.key === 'ArrowLeft' && !query && browse !== '') {
                e.preventDefault();
                var up = idx.byId[browse];
                level(up && idx.byId[_key(up.parent)] ? _key(up.parent) : '');
            }
        });
        root.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && details.open) { e.preventDefault(); close(); }
        });
    }

    /* Enhances the pickers in container — each once. */
    function init(container) {
        var roots = Array.prototype.slice.call(container.querySelectorAll('[data-tree-picker]'));
        roots.forEach(function (root) { if (!root.hasAttribute('data-tree-picker-on')) _enhance(root); });
    }

    function bind() {
        // A click outside an open picker closes it. A click whose target a re-render has just
        // removed (› or a step of the path) is no longer in the page — and not outside.
        document.addEventListener('click', function (e) {
            if (!e.target || !e.target.isConnected) return;
            document.querySelectorAll('[data-tree-picker-on] details[open]').forEach(function (d) {
                var root = d.closest('[data-tree-picker]');
                if (root && !root.contains(e.target)) d.open = false;
            });
        });
        init(document);
    }

    return { bind: bind, init: init, index: index, search: search, marks: marks };
})();

/* ── pick list ──────────────────────────────────────────────────────────────
 * A list to choose ONE entry from by keyboard (first user: «Artikel wählen …», the article picker
 * over the order window, owner 2026-10-19): type to search, ↑/↓ to move, Enter to pick, Esc
 * closes the window («windows»). The server searches and judges the choice; the script only
 * submits the search form and clicks the chosen button (Rule 7) — without it the form searches
 * on Enter and every button picks.
 *
 * Contract:
 *   [data-pick-list]            the container — its search and list are a fetch region, so a
 *                               search reloads only the list (and the field keeps what was typed
 *                               meanwhile, «fetch regions»)
 *   input[data-pick-search]     the search field of a GET form (`data-fetch-region-form`): typing
 *                               searches after a short pause; ↑/↓ move the active item; Enter
 *                               picks the active item — or searches first while the list does
 *                               not show the typed text yet, and waits while it loads
 *   [data-pick-item]            a submit button per entry (the choice); the active one carries
 *                               [data-pick-active] — the server marks the first
 * The focus stays in the search field; a click on an item picks it as well.
 */
_Z77.core.pickList = (function () {
    var DELAY_MS = 250;
    var _timer = null;

    function _items(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[data-pick-item]'));
    }

    function _search(field) {
        clearTimeout(_timer);
        _timer = null;
        var form = field.form;
        if (!form) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    /* Pure: the active index after a step of dir (-1 / +1) from at (-1 = none) over n items. */
    function step(at, dir, n) {
        if (n < 1) return -1;
        return Math.max(0, Math.min(n - 1, at < 0 ? (dir > 0 ? 0 : n - 1) : at + dir));
    }

    function _move(list, dir) {
        var items = _items(list), at = -1;
        items.forEach(function (b, i) { if (b.hasAttribute('data-pick-active')) at = i; });
        var next = step(at, dir, items.length);
        if (next < 0) return;
        items.forEach(function (b, i) { b.toggleAttribute('data-pick-active', i === next); });
        if (typeof items[next].scrollIntoView === 'function') items[next].scrollIntoView({ block: 'nearest' });
    }

    function _field(e) {
        var field = e.target;
        return field && field.matches && field.matches('input[data-pick-search]') && field.closest('[data-pick-list]') ? field : null;
    }

    function bind() {
        document.addEventListener('input', function (e) {
            var field = _field(e);
            if (!field) return;
            clearTimeout(_timer);
            _timer = setTimeout(function () { _search(field); }, DELAY_MS);
        });
        document.addEventListener('keydown', function (e) {
            var field = _field(e);
            if (!field || e.isComposing) return;
            var list = field.closest('[data-pick-list]');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                _move(list, e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if (_timer) { _search(field); return; }   // the list does not show the typed text yet
            if (list.querySelector('[data-fetch-region][aria-busy="true"]')) return;   // … nor while it loads
            var active = list.querySelector('[data-pick-item][data-pick-active]') || _items(list)[0];
            if (active) active.click();
            else _search(field);
        });
    }

    return { bind: bind, step: step };
})();

/* ── small triggers ────────────────────────────────────────────────────────
 * Behaviour a template would otherwise write as an inline handler (`onclick="…"`), which a
 * CSP limited to the own origin blocks (Rule 7). Delegated on `document`: content a swap
 * brings in needs no wiring.
 *   [data-print]             prints the page. Render it `hidden` — revealed here, so a page
 *                            without this script shows no dead button.
 *   [data-select-on-focus]   a readonly field (a link to copy) selects its whole text when it
 *                            is focused or clicked.
 *   select[data-switch]      shows, inside its nearest [data-switch-scope], only the
 *                            [data-switch-case="<value>"] of the chosen value (`hidden` on the
 *                            others; their fields disabled) — the server renders every case, the
 *                            script only toggles (first user: the order's address cards,
 *                            2026-10-18). Without the script the case of the rendered choice stays
 *                            shown.
 *   select[data-range-presets]  a choice of named date ranges (the financial reports' «Zeitraum»,
 *                            owner 2026-10-22): each option carries `data-from` / `data-to`;
 *                            picking one fills the date fields named by the select's
 *                            `data-range-from` / `data-range-to` (element ids; «from» optional —
 *                            a «Stichtag» has none), and editing such a date selects the option
 *                            that agrees with the dates, else the option without dates («Eigene
 *                            Daten»). The server resolves the choice on its own — without the
 *                            script the form still says what it shows after the submit.
 */
_Z77.core.triggers = (function () {
    function _select(el) {
        if (el && typeof el.select === 'function') el.select();
    }

    function _rangeField(select, attr) {
        var id = select.getAttribute(attr);
        return id ? document.getElementById(id) : null;
    }

    /* The chosen option's dates into the fields — the option without dates keeps them. */
    function _fillRange(select) {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.hasAttribute('data-to')) return;
        var from = _rangeField(select, 'data-range-from');
        var to = _rangeField(select, 'data-range-to');
        if (from) from.value = opt.getAttribute('data-from');
        if (to) to.value = opt.getAttribute('data-to');
    }

    /* After a date was edited: keep the choice while it agrees, else the first that does, else none. */
    function _matchRange(select) {
        var from = _rangeField(select, 'data-range-from');
        var to = _rangeField(select, 'data-range-to');
        function agrees(o) {
            return o.hasAttribute('data-to') && (!to || o.getAttribute('data-to') === to.value)
                && (!from || o.getAttribute('data-from') === from.value);
        }
        var current = select.options[select.selectedIndex];
        if (current && agrees(current)) return;
        for (var i = 0; i < select.options.length; i++) {
            if (agrees(select.options[i])) { select.selectedIndex = i; return; }
        }
        select.value = '';
    }

    function bind() {
        var prints = document.querySelectorAll('[data-print][hidden]');
        for (var i = 0; i < prints.length; i++) prints[i].hidden = false;

        document.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            if (e.target.closest('[data-print]')) { window.print(); return; }
            _select(e.target.closest('[data-select-on-focus]'));
        });
        document.addEventListener('focusin', function (e) {
            if (e.target.matches && e.target.matches('[data-select-on-focus]')) _select(e.target);
        });
        document.addEventListener('change', function (e) {
            if (!e.target.matches || !e.target.matches('select[data-switch]')) return;
            var scope = e.target.closest('[data-switch-scope]');
            if (!scope) return;
            var value = e.target.value;
            scope.querySelectorAll('[data-switch-case]').forEach(function (el) {
                el.hidden = el.getAttribute('data-switch-case') !== value;
                // A hidden case's fields are not part of the form (not sent, not compared by
                // «dirty forms») — the case shown again takes them back.
                el.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = el.hidden; });
            });
        });
        document.addEventListener('change', function (e) {
            var t = e.target;
            if (!t.matches) return;
            if (t.matches('select[data-range-presets]')) { _fillRange(t); return; }
            if (!t.matches('input[type="date"]') || !t.form || !t.id) return;
            var select = t.form.querySelector('select[data-range-presets]');
            if (select && (select.getAttribute('data-range-to') === t.id || select.getAttribute('data-range-from') === t.id)) _matchRange(select);
        });
    }

    return { bind: bind };
})();

/* ── sticky top under the action bar ──────────────────────────────────────
 * A form's action bar (`.z77-form-actions`, ADR-049) sticks at the top of its scroll area; a
 * second sticky block in the same form (the order's positions head, owner 2026-10-20) must stick
 * BELOW it, not under it. The bar's height is not fixed (it wraps, «2 Fehler» comes and goes),
 * so this publishes it as `--z77-form-actions-h` on the bar's parent; CSS uses
 * `top: var(--z77-form-actions-h, 0px)`. The bar's own `top` counts in: in a window it sticks
 * ABOVE the body's content edge (a negative top, `_windows-host.scss`), so its bottom is higher.
 * Geometry only — without the script the block sticks at 0.
 */
_Z77.core.stickyTop = (function () {
    var _observer = typeof ResizeObserver === 'function'
        ? new ResizeObserver(function (entries) { entries.forEach(function (entry) { _publish(entry.target); }); })
        : null;

    function _publish(bar) {
        var top = parseFloat(getComputedStyle(bar).top) || 0;
        if (bar.parentElement) bar.parentElement.style.setProperty('--z77-form-actions-h', (bar.offsetHeight + top) + 'px');
    }

    /* Publishes (and follows) the height of every top action bar in container — each once. */
    function init(container) {
        container.querySelectorAll('.z77-form-actions:not(.z77-form-actions--end)').forEach(function (bar) {
            if (bar._z77StickyTop) return;
            bar._z77StickyTop = true;
            _publish(bar);
            if (_observer) _observer.observe(bar);
        });
    }

    return { init: init };
})();

/* ── context menu ──────────────────────────────────────────────────────────
 * A row's ⋮ opens its actions as a CONTEXT MENU that hangs at the ⋮ — no dialog, no header,
 * no «Schliessen», no ×, no full screen (owner 2026-10-22). The server still decides what the
 * menu offers (the row's `actions` endpoint renders the items); the script only places,
 * opens and closes it (Rule 7).
 *
 * Contract:
 *   [data-menu-open="<url>"]   a trigger (a <button type="button">; carry aria-haspopup="menu"
 *                              aria-expanded="false"): GET <url> in fetch mode, the answer's HTML
 *                              (a server-rendered fragment — the same channel the popup takes)
 *                              becomes the menu. Delegated on `document`: a replaced or inserted
 *                              row needs no wiring. Without the script the button does nothing.
 *   In the answer: role="menu" on the root; the items are its <a href> and <button>s
 *   (role="menuitem"; a <form>'s submit button counts — «Jetzt starten»); a checkbox (a real
 *   switch row — the Drive's «Aktiv») takes the focus too but keeps the menu open when it toggles;
 *   any other content (a note) is shown, never focused. The fragment is wired like popup content (`wire()`):
 *   `data-fetch-get` / `data-fetch-post` inside work, from inside a window with that window as
 *   context. An error status or an envelope (session expired, «nicht gefunden») goes through
 *   the envelope dispatch — no menu opens.
 *
 * ONE menu at a time: opening another closes the first, a second click on the same ⋮ closes it.
 * Placed `position: fixed` from the ⋮'s getBoundingClientRect — «eher unten rechts» (owner
 * 2026-10-22): below it, opening to the right (left edges aligned); up when there is no room
 * below; to the left (right edges aligned) when it would leave the viewport on the right;
 * clamped to the viewport with a 4px margin. A fixed box does not follow a scrolling list, so it CLOSES on a scroll outside itself
 * and on a resize (it never floats away from its row). Appended to the ⋮'s <dialog> when it
 * stands in one (the top layer), else to <body> (above the windows: `.z77-menu` in the host CSS).
 * Closes on Esc, a click outside, Tab, and a click on an item — after the item's own behaviour
 * ran (a dialog opens, a link navigates, a form posts). Keys: the first item takes the focus;
 * ↑/↓ wrap, Home/End; Enter/Space activate; the focus goes back to the ⋮ on close (not after a
 * click outside, a scroll or a resize — the focus stays where the user is).
 */
_Z77.core.menu = (function () {
    var ITEMS = 'a[href], button:not([disabled]), input[type="checkbox"]:not([disabled])';   // the focus moves over these
    var ACTS  = 'a[href], button';   // a click on one of these closes the menu (a switch keeps it)
    var _el = null;        // the open menu
    var _trigger = null;   // its ⋮
    var _ticket = 0;       // the latest request — an answer for a menu closed or replaced meanwhile is dropped

    function isOpen() { return !!_el; }

    function _items() {
        return _el ? Array.prototype.slice.call(_el.querySelectorAll(ITEMS)).filter(function (i) { return !i.hidden; }) : [];
    }

    function close(refocus) {
        _ticket++;
        if (!_el) return;
        var el = _el, trigger = _trigger;
        _el = null;
        _trigger = null;
        if (el.parentNode) el.parentNode.removeChild(el);
        trigger.setAttribute('aria-expanded', 'false');
        if (refocus && trigger.isConnected) trigger.focus();
    }

    /* Below the ⋮, opening to the right; flipped up / to the left when out of view. */
    function _place(el, trigger) {
        var t = trigger.getBoundingClientRect();
        var w = el.offsetWidth, h = el.offsetHeight, gap = 2, edge = 4;
        var vw = document.documentElement.clientWidth || window.innerWidth;
        var vh = window.innerHeight || document.documentElement.clientHeight;
        var left = t.left;
        if (left + w > vw - edge) left = t.right - w;
        left = Math.max(edge, Math.min(left, vw - w - edge));
        var top = t.bottom + gap;
        if (top + h > vh - edge && t.top - gap - h >= edge) top = t.top - gap - h;
        el.style.left = Math.round(left) + 'px';
        el.style.top = Math.round(Math.max(edge, top)) + 'px';
    }

    function _show(trigger, html, url) {
        var extracted = _Z77.core.fetch.extractEnvelope(html);
        var win = _Z77.core.windows.of(trigger);
        var ctx = win ? { window: win.getAttribute('data-z77-window') } : undefined;
        var el = document.createElement('div');
        el.className = 'z77-menu';
        el.setAttribute('data-z77-menu', '');
        el.innerHTML = extracted.html;
        (trigger.closest('dialog') || document.body).appendChild(el);
        _el = el;
        _trigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        _Z77.core.wire(el, url, ctx);
        _place(el, trigger);
        el.addEventListener('keydown', _keys);
        var first = _items()[0];
        if (first) first.focus();
        if (extracted.envelope) _Z77.core.fetch.handleEnvelope(extracted.envelope, url, ctx);
    }

    function open(trigger) {
        var url = trigger.getAttribute('data-menu-open');
        if (!url) return null;
        close(false);
        var ticket = _ticket;
        return _Z77.core.busy.guard(trigger, null, function () {
            return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (r.ok && ct.indexOf('text/html') !== -1) return r.text();
                return _Z77.core.fetch.unusable(r, url).then(function () { return null; });
            }).then(function (html) {
                if (html === null || ticket !== _ticket || !trigger.isConnected) return;
                _show(trigger, html, url);
            }, function () {
                _Z77.core.message.show('error', _Z77.core.i18n.t('js.connectionError', 'Verbindungsfehler'));
            });
        });
    }

    function _keys(e) {
        var items = _items();
        var at = items.indexOf(document.activeElement);
        var next;
        if (e.key === 'ArrowDown') next = at < 0 ? 0 : (at + 1) % items.length;
        else if (e.key === 'ArrowUp') next = at < 0 ? items.length - 1 : (at - 1 + items.length) % items.length;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = items.length - 1;
        else if (e.key === 'Tab') { e.preventDefault(); close(true); return; }
        else if (e.key === ' ' && at >= 0 && items[at].tagName === 'A') { e.preventDefault(); items[at].click(); return; }
        else return;
        e.preventDefault();
        if (items[next]) items[next].focus();
    }

    function bind() {
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) return;
            if (_el && _el.contains(t)) {
                // The item's own behaviour first (its listener, a submit, a link) — then close.
                if (t.closest(ACTS)) setTimeout(function () { close(true); }, 0);
                return;
            }
            var opener = t.closest('[data-menu-open]');
            if (opener) {
                e.preventDefault();
                if (_trigger === opener) close(true);
                else open(opener);
                return;
            }
            if (_el && t.isConnected) close(false);
        });
        // Capture: Esc closes the menu before a window or the dialog under it sees the key.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !_el) return;
            e.preventDefault();
            e.stopPropagation();
            close(true);
        }, true);
        document.addEventListener('scroll', function (e) {
            if (_el && !(e.target && e.target.nodeType === 1 && _el.contains(e.target))) close(false);
        }, true);
        window.addEventListener('resize', function () { close(false); });
    }

    return { bind: bind, open: open, close: close, isOpen: isOpen };
})();

/* ── boot ───────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    _Z77.core.triggers.bind();
    _Z77.core.dirty.bind();
    _Z77.core.fill.bind();
    _Z77.core.rows.bind();
    _Z77.core.autosize.bind();
    _Z77.core.treePicker.bind();
    _Z77.core.pickList.bind();
    _Z77.core.sums.bind();
    _Z77.core.datedRates.bind();
    _Z77.core.priceMode.bind();
    _Z77.core.windows.bind();
    _Z77.core.menu.bind();
    _Z77.core.help.bind();
    _Z77.core.region.bind();
    _Z77.core.submitNavigate.bind();
    _Z77.core.i18n.load();
    _Z77.core.wire(document);
    _bindCheckUrl(document);
    _Z77.core.popup.bindBackdrop();
    _Z77.core.flash.wireExisting();
    _Z77.core.message.wireExisting();
});

/* ── scrollbar width ───────────────────────────────────────────────────────
 * Publishes `--z77-scrollbar-width` = the viewport scrollbar's width, so that
 * scroll-locking (overflow:hidden on a nav overlay / modal) can add an equal
 * `padding-right` and NOT shift the page when the scrollbar disappears (the
 * classic no-jump technique). Measured as innerWidth - clientWidth while a
 * scrollbar is present; skipped while scroll is already locked (overflow:hidden
 * reports 0), so the last good value is kept. Module-agnostic — sets a generic
 * variable, references no module-specific markup. Deferred, so the DOM is already
 * parsed on first run.
 */
(function () {
    var de = document.documentElement;
    function update() {
        if (getComputedStyle(de).overflowY === 'hidden') return;   // don't measure while locked
        de.style.setProperty('--z77-scrollbar-width', (window.innerWidth - de.clientWidth) + 'px');
    }
    update();
    window.addEventListener('resize', update);
    window.addEventListener('load', update);
})();
