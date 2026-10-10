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
 *   [data-window-open="<url>"]    opens a controller-led window (ADR-047, see «windows»)
 *   [data-fetch-region="<name>"]  a part of the page that reloads alone —
 *     a[data-fetch-region-link] / form[data-fetch-region-form] inside it (see «fetch regions»)
 *
 * Native semantics carry validity state:
 *   input/select … aria-invalid="true|false"
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

    function bindBackdrop() {
        var root = _root();
        if (!root || root._z77PopupBound) return;
        root._z77PopupBound = true;
        // No close-on-backdrop-click: an edit is only discarded via the explicit
        // cancel control ([data-popup-close]) so a stray click — or a text
        // selection dragged onto the backdrop — never loses the form.
        root.addEventListener('click', function (e) {
            if (e.target.closest('[data-popup-fullscreen]')) { root.toggleAttribute('data-fullscreen'); return; }
            if (e.target.closest('[data-popup-close]')) close();
        });
    }

    return { show: show, close: close, bindBackdrop: bindBackdrop };
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

/* ── generic wire (data-fetch-post / data-fetch-get) ────────────────────── */
/* `ctx` (optional): {window: id} when the container is a window's body (ADR-047) — a
 * request fired from inside a window answers INTO that window. */
_Z77.core.wire = function (container, defaultPostUrl, ctx) {
    container.querySelectorAll('[data-fetch-post]').forEach(function (form) {
        var url = form.dataset.fetchPost || defaultPostUrl;
        if (!url) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            _Z77.core.fetch.post(url, _z77CollectFormData(form), ctx);
        });
    });
    container.querySelectorAll('[data-fetch-get]').forEach(function (el) {
        el.addEventListener('click', function () {
            _Z77.core.fetch.get(el.dataset.fetchGet, ctx);
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
    container.querySelectorAll('[data-fetch-toggle]').forEach(function (el) {
        el.addEventListener('change', function () {
            _Z77.core.fetch.post(el.dataset.fetchToggle, { value: el.checked })
                .then(function (env) {
                    if (!env || env.status !== 'success') { el.checked = !el.checked; }
                });
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

    function _parseResponse(r, sourceUrl, ctx) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('text/html') !== -1) {
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
    registerEnvelopeHandler('fields', function (fields) {
        if (!fields) return;
        Object.keys(fields).forEach(function (name) {
            var info = fields[name];
            if (info && info.valid === false) {
                _Z77.core.fields.mark(document, name, info.message || '');
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
    registerCommand('replace-html', function (p) {
        var el = _find(p);
        if (!el) return;
        var parent = el.parentNode, key = _focusKey(el);
        parent.replaceChild(_wiredFragment(p.html, p.origin), el);
        if (key) {
            var again = parent.querySelector(key);
            if (again && typeof again.focus === 'function') again.focus({ preventScroll: true });
        }
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
    registerCommand('close-modal', function (_p, _d, ctx) {
        var win = ctx && ctx.window ? _Z77.core.windows.byId(ctx.window) : null;
        if (win) { _Z77.core.windows.close(win, true); return; }
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
                el.innerHTML = data[key];
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
 * Focus returns to the element with the same id when there is one. When the
 * answer carries no such region, the browser simply navigates there.
 */
_Z77.core.region = (function () {
    function _load(region, url) {
        var name = region.getAttribute('data-fetch-region');
        var focusId = document.activeElement && region.contains(document.activeElement) ? document.activeElement.id : '';
        region.setAttribute('aria-busy', 'true');
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('[data-fetch-region="' + name + '"]');
                if (!fresh) { window.location.href = url; return; }
                region.replaceWith(fresh);
                history.replaceState(history.state, '', url);
                if (focusId) {
                    var el = document.getElementById(focusId);
                    if (el) {
                        el.focus();
                        if (typeof el.setSelectionRange === 'function' && typeof el.value === 'string') {
                            try { el.setSelectionRange(el.value.length, el.value.length); } catch (e) { /* not a text field */ }
                        }
                    }
                }
            })
            .catch(function () { window.location.href = url; });
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
 *   In the controller's answer, on the content's root element:
 *   [data-window="<mask>"]              the mask (form) this window is
 *   [data-window-entity="<type>:<id>"]  the record it shows
 *   [data-window-title="…"]             the title bar text
 *   [data-window-width="<length>"]      (optional) the width the controller wants for this content
 *                                       (e.g. `62rem`; rem/ch/px/%/vw) — a phone ignores it
 *   [data-window-confirm-close="…"]     ask this question before closing (the controller's call)
 *   Inside a window:
 *   a[data-window-link]         loads its href into THIS window (read view ↔ edit form)
 *   [data-window-close]         closes this window (and its children); so does
 *                               [data-popup-close] inside a window, × and Esc
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

    function _build(parentId, origin) {
        var win = document.createElement('section');
        win.className = 'z77-window';
        win.setAttribute('data-z77-window', String(++_seq));
        win.setAttribute('role', 'dialog');
        win.setAttribute('aria-modal', 'true');
        win.tabIndex = -1;
        if (parentId) win.setAttribute('data-z77-window-parent', parentId);
        win._z77Origin = origin || 'page';
        win.innerHTML = '<header class="z77-window__head"><span class="z77-window__title"></span>'
            + '<button type="button" class="z77-window__close" data-window-close aria-label="'
            + _Z77.core.i18n.t('common.close', 'Schliessen') + '">×</button></header>'
            + '<div class="z77-window__body"></div>';
        return win;
    }

    /* The «i» in the title bar (ADR-048): there while the content carries a help template, gone
     * when new content (read view ↔ edit form) has none. */
    function _helpButton(win, body) {
        var head = win.querySelector('.z77-window__head');
        var btn  = head.querySelector('[data-help-open]');
        var has  = !!body.querySelector('template[data-help]');
        if (has && !btn) {
            var label = _Z77.core.i18n.t('common.help', 'Hilfe');
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'z77-help-open';
            btn.setAttribute('data-help-open', '');
            btn.setAttribute('aria-label', label);
            btn.title = label;
            btn.textContent = 'i';
            head.insertBefore(btn, head.querySelector('.z77-window__close'));
        } else if (!has && btn) {
            btn.parentNode.removeChild(btn);
        }
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

    function _get(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.text(); });
    }

    /* Opens <url> as a window — or brings the window that already shows it to the front. */
    function open(url, opts) {
        opts = opts || {};
        var target = _url(url, opts.origin);
        return _get(target).then(function (html) {
            var probe = document.createElement('div');
            probe.innerHTML = _Z77.core.fetch.extractEnvelope(html).html;
            var hit = _conflict(probe.querySelector('[data-window]'));
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
            win.focus();
            return win;
        }).catch(function () { window.location.href = url; });
    }

    /* Loads <url> into an open window (read view ↔ edit form); the origin stays the window's. */
    function load(win, url) {
        var target = _url(url, win._z77Origin);
        return _get(target).then(function (html) { fill(win, html, target); front(win); });
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
            var opener = e.target.closest('[data-window-open]');
            if (opener) {
                e.preventDefault();
                var parent = of(opener);
                open(opener.getAttribute('data-window-open'), {
                    origin: _originOf(opener),
                    parent: parent ? parent.getAttribute('data-z77-window') : ''
                });
                return;
            }
            var win = of(e.target);
            if (!win) return;
            var link = e.target.closest('a[data-window-link]');
            if (link) { e.preventDefault(); load(win, link.href); return; }
            if (e.target.closest('[data-window-close], [data-popup-close]')) { close(win); return; }
            front(win);
        });
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var win = of(form);
            if (!win || e.defaultPrevented || form.hasAttribute('data-fetch-post')) return;
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            e.preventDefault();
            var data = e.submitter ? new FormData(form, e.submitter) : new FormData(form);
            _Z77.core.fetch.postForm(form.action, data, { window: win.getAttribute('data-z77-window') });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !all().length || document.querySelector('dialog[open]')) return;
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
        _win.innerHTML = '<header class="z77-help__head"><span class="z77-help__title" id="z77-help-title"></span>'
            + '<button type="button" class="z77-help__full" data-help-full aria-pressed="false" aria-label="'
            + t('common.fullscreen', 'Vollbild') + '" title="' + t('common.fullscreen', 'Vollbild') + '">⤢</button>'
            + '<button type="button" class="z77-help__close" data-help-close aria-label="'
            + t('common.close', 'Schliessen') + '" title="' + t('common.close', 'Schliessen') + '">×</button></header>'
            + '<div class="z77-help__body"></div>';
        document.body.appendChild(_win);
        _drag(_win.querySelector('.z77-help__head'));
        return _win;
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

/* ── boot ───────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    _Z77.core.windows.bind();
    _Z77.core.help.bind();
    _Z77.core.region.bind();
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
