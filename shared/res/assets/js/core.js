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
_Z77.core.wire = function (container, defaultPostUrl) {
    container.querySelectorAll('[data-fetch-post]').forEach(function (form) {
        var url = form.dataset.fetchPost || defaultPostUrl;
        if (!url) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            _Z77.core.fetch.post(url, _z77CollectFormData(form));
        });
    });
    container.querySelectorAll('[data-fetch-get]').forEach(function (el) {
        el.addEventListener('click', function () {
            _Z77.core.fetch.get(el.dataset.fetchGet);
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

    var _envelopeHandlers = {};   // key → fn(value, envelope, sourceUrl)
    var _commandHandlers  = {};   // action → fn(payload, envelopeData)

    function registerEnvelopeHandler(key, fn) { _envelopeHandlers[key] = fn; }
    function registerCommand(action, fn)      { _commandHandlers[action] = fn; }

    function _executeCommands(commands, data) {
        (commands || []).forEach(function (cmd) {
            var handler = _commandHandlers[cmd.action];
            if (handler) handler(cmd, data);
        });
    }

    function _handleEnvelope(env, sourceUrl) {
        if (!env) return;
        Object.keys(env).forEach(function (key) {
            var handler = _envelopeHandlers[key];
            if (handler) handler(env[key], env, sourceUrl);
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

    function _parseResponse(r, sourceUrl) {
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('text/html') !== -1) {
            return r.text().then(function (html) {
                var extracted = _extractEmbeddedEnvelope(html);
                var handler   = _envelopeHandlers['html'];
                if (handler) handler(extracted.html, extracted.envelope, sourceUrl);
                if (extracted.envelope) _handleEnvelope(extracted.envelope, sourceUrl);
                return { status: 'html', html: extracted.html, envelope: extracted.envelope };
            });
        }
        return r.json().then(function (env) {
            _handleEnvelope(env, sourceUrl);
            return env;
        });
    }

    function post(url, data) {
        return fetch(url, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     _csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data || {})
        })
        .then(function (r) { return _parseResponse(r, url); })
        .catch(function () { _Z77.core.message.show('error', _Z77.core.i18n.t('js.connectionError', 'Verbindungsfehler')); });
    }

    function get(url) {
        return fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return _parseResponse(r, url); })
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
    registerEnvelopeHandler('commands', function (cmds, env) {
        _executeCommands(cmds, env && env.data);
    });
    registerEnvelopeHandler('html', function (html, _env, sourceUrl) {
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
    registerCommand('replace-html', function (p) {
        var el = document.querySelector(p.target);
        if (el) el.outerHTML = p.html;
    });
    registerCommand('remove-element', function (p) {
        var el = document.querySelector(p.target);
        if (el) el.parentNode.removeChild(el);
    });
    registerCommand('insert-html', function (p) {
        var el = document.querySelector(p.target);
        if (!el) return;
        var map = { prepend: 'afterbegin', before: 'beforebegin', after: 'afterend', append: 'beforeend' };
        el.insertAdjacentHTML(map[p.position] || 'beforeend', p.html);
    });
    registerCommand('update-text', function (p) {
        var el = document.querySelector(p.target);
        if (el) el.textContent = p.text;
    });
    registerCommand('update-html', function (p) {
        var el = document.querySelector(p.target);
        if (el) el.innerHTML = p.html;
    });
    registerCommand('scroll-to', function (p) {
        var el = document.querySelector(p.target);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
    registerCommand('reload', function () { window.location.reload(); });

    /* ── popup commands ────────────────────────────────────────────────── */
    registerCommand('close-modal', function () { _Z77.core.popup.close(); });

    /* ── update [data-field] slots inside a container ──────────────────── */
    registerCommand('update-fields', function (p, data) {
        var container = document.querySelector(p.target);
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
        var el = document.querySelector(p.target);
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
        post: post,
        get:  get,
        registerEnvelopeHandler: registerEnvelopeHandler,
        registerCommand:         registerCommand
    };
})();

/* ── boot ───────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
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
