/* upload.js — THE upload for the whole framework (owner 2026-10-08/09, canvas board
 * «Upload — Entwurf»). One component for the payment-import, the Drive, a logo field and
 * every import that follows: click opens the file dialog, dragging drops files anywhere on
 * the work area, each file shows its own progress and its own outcome.
 *
 * Rule 7 justification (as little JavaScript as possible): a plain file field cannot report
 * progress, cannot accept a drop, and cannot say per file what became of it. Without this
 * script the form stays a normal multipart form with a «Hochladen» button — the server
 * answers a redirect then, so the feature works, only without those three things. Nothing
 * here BUILDS a control: the zone is a `<label for>` over a real `<input type=file>`.
 *
 * The contract is written by `partials/upload` out of `Z77\Shared\Upload\UploadPolicy`, so
 * the client never carries a limit of its own:
 *
 *   form[data-upload="drop|cell|field"]   the component; `action` = the per-file endpoint
 *        data-upload-max="<bytes>"        per file, already capped to what PHP accepts
 *        data-upload-accept=".xml,image/*"
 *        data-upload-multiple             absent = exactly one file
 *        data-upload-conflict="ask|overwrite|skip|error"   the ENDPOINT's rule, per endpoint
 *   [data-upload-zone] [data-upload-list] [data-upload-summary] [data-upload-bar]
 *   [data-upload-count] [data-upload-submit]
 *
 * Per-file answer (JSON, one request per file):
 *   {status:"ok", name, message?, commands?} · {status:"conflict", name} ·
 *   {status:"error", name, message} — `commands` are handed to the fetch channel, so an
 *   endpoint can refresh a region or open a window when its queue is through.
 *
 * THREE AT A TIME: a browser allows about six connections per host, and real progress needs
 * one XHR per file (fetch() cannot report upload progress). Three leaves room for the page
 * itself and is three times faster than a strict queue on ten files; more would only make
 * every single bar slower without finishing earlier.
 */
window._Z77 = window._Z77 || {};

_Z77.upload = (function () {
    var PARALLEL = 3;

    var _meta  = document.querySelector('meta[name="csrf-token"]');
    var _csrf  = _meta ? _meta.getAttribute('content') : '';
    var _forms = [];

    /* A module may add FIELDS PER FILE that only it can produce — the Drive's poster frame
     * out of a video is the case this exists for (a canvas grab in the browser; the kernel
     * has no business knowing about videos). `data-upload-extra="<name>"` on the form picks
     * one; it answers an object of extra form fields, or a promise of one, and a rejection
     * is not fatal — the file goes up without them. */
    var _providers = {};

    /* A module may carry a file over ANOTHER WIRE than one multipart POST — the DMS chunked
     * upload (F3) for a file bigger than one request may be. `data-upload-transport="<name>"`
     * picks one, `data-upload-transport-config` hands it the server's numbers. A transport:
     *   accepts(file, config) → bool    takes THIS file? (no = the normal POST)
     *   max(config)           → bytes   its own cap for the files it takes, 0 = none
     *   send(file, ctx)       → Promise of the per-file answer the endpoint would have sent
     *                                   ({status, data, commands}); a rejection is the row's error
     * ctx = {config, overwrite, fields (the form's own), extra (the provider's), signal,
     *        onProgress(loaded, total)}. The rows, the conflict question, the commands at the
     * end of the run stay this component's — a transport only moves bytes. */
    var _transports = {};

    function _json(value) {
        if (!value) return {};
        try { return JSON.parse(value) || {}; } catch (e) { return {}; }
    }

    function _bytes(n) {
        if (n >= 1024 * 1024) {
            var mb = n / (1024 * 1024);
            return (mb < 10 ? mb.toFixed(1) : Math.round(mb)) + ' MB';
        }
        return Math.max(1, Math.round(n / 1024)) + ' KB';
    }

    /* ── one component ──────────────────────────────────────────────────── */

    function Uploader(form) {
        this.form     = form;
        this.shape    = form.getAttribute('data-upload') || 'drop';
        this.max      = parseInt(form.getAttribute('data-upload-max'), 10) || 0;
        this.accept   = (form.getAttribute('data-upload-accept') || '').split(',')
                            .map(function (s) { return s.trim().toLowerCase(); })
                            .filter(function (s) { return s !== ''; });
        this.multiple = form.hasAttribute('data-upload-multiple');
        this.conflict = form.getAttribute('data-upload-conflict') || 'ask';
        this.maxPer   = _json(form.getAttribute('data-upload-max-per'));
        this.extra    = form.getAttribute('data-upload-extra') || '';
        this.transport       = form.getAttribute('data-upload-transport') || '';
        this.transportConfig = _json(form.getAttribute('data-upload-transport-config'));
        this.input    = form.querySelector('input[type=file]');
        this.list     = form.querySelector('[data-upload-list]');
        this.summary  = form.querySelector('[data-upload-summary]');
        this.bar      = form.querySelector('[data-upload-bar]');
        this.count    = form.querySelector('[data-upload-count]');
        this.items    = [];
        this.running  = 0;
        this.commands = [];
    }

    /** Does one file match `accept`? Extensions by name, types by the browser's guess. */
    Uploader.prototype.accepts = function (file) {
        if (this.accept.length === 0) return true;
        var name = (file.name || '').toLowerCase();
        var type = (file.type || '').toLowerCase();

        return this.accept.some(function (entry) {
            if (entry.charAt(0) === '.') return name.slice(-entry.length) === entry;
            if (entry.slice(-2) === '/*') return type.indexOf(entry.slice(0, -1)) === 0;
            return entry === type;
        });
    };

    /**
     * The client's own gate — a courtesy, not the decision: the same check runs on the
     * server (`UploadPolicy::check()`), which is what a request without this script meets.
     */
    Uploader.prototype.reject = function (file) {
        var limit = this.limitFor(file);
        if (!this.accepts(file))              return 'nicht erlaubt (' + this.accept.join(', ') + ')';
        if (limit > 0 && file.size > limit)   return 'zu gross (max. ' + _bytes(limit) + ')';
        if (file.size === 0)                  return 'leere Datei';
        return null;
    };

    /**
     * The cap for ONE file: the general one, lowered by the first matching entry of
     * `data-upload-max-per` — the same resolution `UploadPolicy::maxBytesFor()` does, which
     * is why both read the same map (an image must also fit in the server's memory, a video
     * only through the wire). A file a transport takes is bounded by the transport's cap
     * instead of the one-request cap (`0` = none) — the per-kind entry still lowers it.
     */
    Uploader.prototype.limitFor = function (file) {
        var name    = (file.name || '').toLowerCase();
        var type    = (file.type || '').toLowerCase();
        var carrier = this.carrier(file);
        var base    = carrier ? (carrier.max(this.transportConfig) || 0) : this.max;
        var hit     = Object.keys(this.maxPer).filter(function (pattern) {
            var p = pattern.toLowerCase();
            if (p.charAt(0) === '.')  return name.slice(-p.length) === p;
            if (p.slice(-2) === '/*') return type !== '' && type.indexOf(p.slice(0, -1)) === 0;
            return p === type;
        })[0];

        return hit ? Math.min(base || Infinity, this.maxPer[hit]) : base;
    };

    /** The transport that takes this file, or null for the normal one-request POST. */
    Uploader.prototype.carrier = function (file) {
        var transport = _transports[this.transport];

        return transport && transport.accepts(file, this.transportConfig) ? transport : null;
    };

    Uploader.prototype.add = function (files) {
        var self = this;
        var list = Array.prototype.slice.call(files || []);
        if (list.length === 0) return;

        if (!this.multiple) {
            list = list.slice(0, 1);
            this.items.forEach(function (item) { self.cancel(item); });
        }

        list.forEach(function (file) {
            var item = { file: file, state: 'queued', loaded: 0, xhr: null };
            item.row = self.row(item);
            self.items.push(item);
            self.list.appendChild(item.row.el);

            var why = self.reject(file);
            if (why) {
                self.finish(item, 'error', why);
            }
        });

        this.list.hidden    = false;
        this.summary.hidden = false;
        this.form.classList.add('is-busy');
        this.form.classList.remove('is-done');
        this.pump();
        this.paint();
    };

    /** One row: name, outcome at the name, its own bar, cancel while it waits or runs. */
    Uploader.prototype.row = function (item) {
        var self = this;
        var el = document.createElement('li');
        el.className = 'z77-upload-row';

        var name = document.createElement('span');
        name.className = 'z77-upload-row__name';
        name.textContent = item.file.name;

        var status = document.createElement('span');
        status.className = 'z77-upload-row__status';

        var icon = document.createElement('span');
        icon.className = 'z77-upload-row__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.hidden = true;

        var text = document.createElement('span');
        text.textContent = 'wartet';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'z77-upload-row__cancel';
        cancel.textContent = 'Abbrechen';
        cancel.addEventListener('click', function () { self.cancel(item); });

        status.appendChild(icon);
        status.appendChild(text);
        status.appendChild(cancel);

        var progress = document.createElement('span');
        progress.className = 'z77-upload-row__progress';
        var fill = document.createElement('span');
        fill.className = 'z77-upload-row__fill';
        progress.appendChild(fill);

        var message = document.createElement('span');
        message.className = 'z77-upload-row__message';
        message.hidden = true;

        el.appendChild(name);
        el.appendChild(status);
        el.appendChild(progress);
        el.appendChild(message);

        return { el: el, icon: icon, text: text, cancel: cancel, fill: fill, message: message };
    };

    /** Starts as many files as the parallel budget allows. */
    Uploader.prototype.pump = function () {
        var self = this;
        while (this.running < PARALLEL) {
            var next = this.items.filter(function (item) { return item.state === 'queued'; })[0];
            if (!next) break;
            this.send(next, false);
        }
        if (this.running === 0) this.done();
        void self;
    };

    /**
     * Start one file. A module's field provider runs FIRST (it may have to decode a video
     * frame), and its failure is not the upload's: the file goes without the extras.
     */
    Uploader.prototype.send = function (item, overwrite) {
        var self     = this;
        var provider = _providers[this.extra];

        item.state = 'running';
        item.row.text.textContent = provider ? 'wird vorbereitet' : '0 %';
        this.running++;

        if (!provider) { this.transmit(item, overwrite, null); return; }

        Promise.resolve()
            .then(function () { return provider(item.file, self.form); })
            .catch(function () { return null; })
            .then(function (fields) { self.transmit(item, overwrite, fields); });
    };

    Uploader.prototype.transmit = function (item, overwrite, extraFields) {
        var self    = this;
        var carrier = this.carrier(item.file);
        if (carrier) { this.carry(item, overwrite, extraFields, carrier); return; }

        var data = new FormData();
        data.append(this.input.getAttribute('name') || 'files[]', item.file, item.file.name);
        if (overwrite) data.append('overwrite', '1');
        // Everything else the form carries travels with EVERY file: the Drive's target
        // folder, its «Original ausliefern» switch, the CSRF field.
        this.form.querySelectorAll('input[type=hidden], select, input[type=checkbox]:checked')
            .forEach(function (field) {
                if (field.name) data.append(field.name, field.value);
            });
        if (extraFields) {
            Object.keys(extraFields).forEach(function (name) {
                var value = extraFields[name];
                if (value === null || value === undefined) return;
                data.append(name, value, value instanceof Blob ? name : undefined);
            });
        }

        var xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', this.form.getAttribute('action'), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        if (_csrf) xhr.setRequestHeader('X-CSRF-Token', _csrf);

        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable) return;
            self.progress(item, e.loaded, e.total);
        };

        xhr.onload = function () {
            self.running--;
            item.xhr = null;
            var answer = null;
            try { answer = JSON.parse(xhr.responseText); } catch (e) { answer = null; }
            self.settle(item, xhr.status, answer);
        };

        xhr.onerror = function () {
            self.running--;
            item.xhr = null;
            self.finish(item, 'error', 'Verbindung unterbrochen');
            self.pump();
        };

        xhr.onabort = function () {
            self.running--;
            item.xhr = null;
            self.pump();
        };

        xhr.send(data);
    };

    /**
     * Hand the file to a module's transport (see `_transports`). It reports progress and
     * answers the same per-file envelope, so the row, the conflict question and the commands
     * work exactly as for one request. Cancelling aborts through `item.xhr` like an XHR.
     */
    Uploader.prototype.carry = function (item, overwrite, extraFields, carrier) {
        var self  = this;
        var ctrl  = typeof AbortController === 'function' ? new AbortController() : null;
        item.xhr  = { abort: function () { if (ctrl) ctrl.abort(); } };

        Promise.resolve()
            .then(function () {
                return carrier.send(item.file, {
                    config:     self.transportConfig,
                    overwrite:  !!overwrite,
                    fields:     self.fields(),
                    extra:      extraFields || {},
                    signal:     ctrl ? ctrl.signal : null,
                    onProgress: function (loaded, total) { self.progress(item, loaded, total); }
                });
            })
            .then(function (answer) {
                self.running--;
                item.xhr = null;
                self.settle(item, 200, answer || null);
            }, function (error) {
                self.running--;
                item.xhr = null;
                if (item.state === 'cancelled') { self.pump(); return; }
                self.finish(item, 'error', (error && error.message) || 'Verbindung unterbrochen');
                self.pump();
            });
    };

    /** The form's own fields as one object — what a transport sends along. */
    Uploader.prototype.fields = function () {
        var out = {};
        this.form.querySelectorAll('input[type=hidden], select, input[type=checkbox]:checked')
            .forEach(function (field) {
                if (field.name) out[field.name] = field.value;
            });
        return out;
    };

    Uploader.prototype.progress = function (item, loaded, total) {
        item.loaded = loaded;
        var percent = total > 0 ? Math.round(loaded / total * 100) : 0;
        item.row.fill.style.width = percent + '%';
        item.row.text.textContent = percent + ' %';
        this.paint();
    };

    /**
     * One file's answer → its row. The house envelope (fetch.md «envelope structure»):
     * `status` plus optional `data` / `flashes` / `messages` / `commands`. Two statuses are
     * this component's own, and the Drive has used them since 2026-07: `duplicate`
     * (identical bytes already there — nothing to do, not an error) and `conflict` (same
     * name, other bytes — the endpoint's rule decides).
     */
    Uploader.prototype.settle = function (item, httpStatus, answer) {
        var self    = this;
        var data    = answer && answer.data ? answer.data : {};
        var message = data.message || (answer ? answer.message : '') || '';

        if (answer && (answer.flashes || answer.messages || answer.fields) && _Z77.core && _Z77.core.fetch) {
            // The row says what happened to THIS file; a flash the endpoint sent on top
            // is its own business — but commands wait for the end of the run.
            var passed = {};
            Object.keys(answer).forEach(function (key) { if (key !== 'commands') passed[key] = answer[key]; });
            _Z77.core.fetch.handleEnvelope(passed, self.form.getAttribute('action'));
        }

        if (httpStatus >= 400 || !answer) {
            this.finish(item, 'error', message || 'Server-Fehler (' + httpStatus + ')');
        } else if (answer.status === 'conflict') {
            this.askOverwrite(item, { message: message });
        } else if (answer.status === 'error' || answer.status === 'validation') {
            this.finish(item, 'error', message || 'nicht gespeichert');
        } else if (answer.status === 'duplicate') {
            this.finish(item, 'skipped', message || 'identisch, schon vorhanden');
        } else {
            this.finish(item, 'done', message || 'hochgeladen');
            // Commands wait for the END of the run, deduplicated: ten files answering
            // «reload» must reload once, after the last one — not ten times, and not
            // while nine uploads are still in flight.
            (answer.commands || []).forEach(function (cmd) {
                var key = JSON.stringify(cmd);
                if (self.commands.every(function (c) { return JSON.stringify(c) !== key; })) {
                    self.commands.push(cmd);
                }
            });
        }
        this.pump();
    };

    /**
     * A name that is already there. WHO decides is the endpoint's business
     * (`data-upload-conflict`): only `ask` puts the question to the user, and only on this
     * one row — the other files keep uploading meanwhile.
     */
    Uploader.prototype.askOverwrite = function (item, answer) {
        var self = this;
        if (this.conflict === 'overwrite') { this.send(item, true); return; }
        if (this.conflict === 'skip')      { this.finish(item, 'skipped', 'übersprungen (vorhanden)'); return; }
        if (this.conflict === 'error')     { this.finish(item, 'error', answer.message || 'Name ist vergeben'); return; }

        item.state = 'asking';
        var prompt = document.createElement('span');
        prompt.className = 'z77-upload-row__prompt';
        prompt.appendChild(document.createTextNode('Name ist vergeben — überschreiben?'));

        var yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'z77-upload-row__cancel';
        yes.textContent = 'Ja';
        var no = document.createElement('button');
        no.type = 'button';
        no.className = 'z77-upload-row__cancel';
        no.textContent = 'Nein';

        yes.addEventListener('click', function () {
            prompt.remove();
            item.state = 'queued';
            self.pump();
        });
        no.addEventListener('click', function () {
            prompt.remove();
            self.finish(item, 'skipped', 'behalten, nichts geändert');
        });

        prompt.appendChild(yes);
        prompt.appendChild(no);
        item.row.el.appendChild(prompt);
        item.row.text.textContent = 'Frage';
        this.paint();
    };

    Uploader.prototype.cancel = function (item) {
        if (item.state === 'done' || item.state === 'error') return;
        if (item.xhr) { item.xhr.abort(); }
        this.finish(item, 'cancelled', 'abgebrochen');
    };

    /** The outcome lands AT the file name — one icon, one short reason. */
    Uploader.prototype.finish = function (item, state, message) {
        item.state = state;
        item.row.el.classList.add('z77-upload-row--' + state);
        item.row.cancel.hidden = true;
        item.row.fill.style.width = '100%';
        item.row.text.textContent = state === 'done' ? 'fertig' : message;

        if (state === 'done' || state === 'error') {
            item.row.icon.hidden = false;
            item.row.icon.textContent = state === 'done' ? '✓' : '!';
        }
        if (state === 'error' && message) {
            item.row.message.hidden = false;
            item.row.message.textContent = message;
        }
        this.paint();
        if (this.running === 0) this.done();
    };

    /** The run's own line: the overall bar plus «2 von 3». */
    Uploader.prototype.paint = function () {
        var total = this.items.reduce(function (sum, item) { return sum + item.file.size; }, 0);
        var moved = this.items.reduce(function (sum, item) {
            return sum + (item.state === 'done' || item.state === 'error' || item.state === 'skipped'
                ? item.file.size
                : item.loaded);
        }, 0);
        var settled = this.items.filter(function (item) { return item.state !== 'queued' && item.state !== 'running' && item.state !== 'asking'; }).length;

        if (this.bar)   this.bar.style.width = (total > 0 ? Math.round(moved / total * 100) : 0) + '%';
        if (this.count) this.count.textContent = settled + ' von ' + this.items.length
            + (settled < this.items.length ? ' · nicht schliessen' : '');
    };

    /**
     * The run is through: the summary flash. It counts, it does not repeat the rows — the
     * reason per file stands at its name, and a flash that lists four messages is a wall.
     */
    Uploader.prototype.done = function () {
        var pending = this.items.some(function (item) {
            return item.state === 'queued' || item.state === 'running' || item.state === 'asking';
        });
        if (pending || this.items.length === 0) return;

        this.form.classList.remove('is-busy');
        this.form.classList.add('is-done');
        if (this.input) this.input.value = '';

        var ok     = this.items.filter(function (i) { return i.state === 'done'; }).length;
        var failed = this.items.filter(function (i) { return i.state === 'error'; }).length;

        if (_Z77.core && _Z77.core.flash) {
            if (failed === 0) {
                _Z77.core.flash.show('success', ok === 1 ? 'Datei hochgeladen' : ok + ' Dateien hochgeladen');
            } else {
                _Z77.core.flash.show('error', failed === 1
                    ? '1 Datei nicht übernommen — der Grund steht bei der Datei'
                    : failed + ' Dateien nicht übernommen — die Gründe stehen bei den Dateien');
            }
        }

        // Now the endpoint's instructions — but ONLY when every file got through. A
        // command closes the modal or replaces the pane the rows live in, and the reason a
        // file was refused stands in its row: carrying that out on a failed run would hide
        // exactly what the user has to read. (The Drive behaved this way before the
        // component existed; it is the general rule now.)
        var commands = this.commands;
        this.commands = [];
        if (failed === 0 && commands.length && _Z77.core && _Z77.core.fetch) {
            _Z77.core.fetch.handleEnvelope({ commands: commands }, this.form.getAttribute('action'));
        }
    };

    /* ── wiring ─────────────────────────────────────────────────────────── */

    /**
     * The drop target: the component itself, and for the `cell` shape the WHOLE work area
     * (the board's «die Seite ist das Ziel»). `dragenter`/`dragleave` fire per element, so
     * a depth counter decides when the drag has really left.
     */
    Uploader.prototype.bindDrop = function (target) {
        var self  = this;
        var depth = 0;

        function allowed(e) {
            var items = e.dataTransfer && e.dataTransfer.items ? e.dataTransfer.items : [];
            var files = Array.prototype.filter.call(items, function (i) { return i.kind === 'file'; });
            if (files.length === 0) return true;                       // Firefox hides the list during drag
            if (!self.multiple && files.length > 1) return false;
            return files.every(function (i) {
                return self.accept.length === 0 || self.accepts({ name: '', type: i.type || '' })
                    || self.accept.some(function (entry) { return entry.charAt(0) === '.'; });   // name unknown while dragging
            });
        }

        target.addEventListener('dragenter', function (e) {
            if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
            depth++;
            self.form.classList.add(allowed(e) ? 'is-over' : 'is-over-bad');
            if (target !== self.form) target.classList.add('is-upload-target');
        });
        target.addEventListener('dragover', function (e) { e.preventDefault(); });
        target.addEventListener('dragleave', function () {
            depth = Math.max(0, depth - 1);
            if (depth === 0) self.clearOver(target);
        });
        target.addEventListener('drop', function (e) {
            e.preventDefault();
            // A `cell` component watches the whole work area, and a `drop` zone can stand
            // INSIDE that area: without this the same files would be queued twice, once per
            // component. The innermost target wins — it is the one the user aimed at.
            e.stopPropagation();
            depth = 0;
            var bad = self.form.classList.contains('is-over-bad');
            self.clearOver(target);
            if (bad) return;
            self.add(e.dataTransfer ? e.dataTransfer.files : null);
        });
    };

    Uploader.prototype.clearOver = function (target) {
        this.form.classList.remove('is-over', 'is-over-bad');
        if (target !== this.form) target.classList.remove('is-upload-target');
    };

    function bind(scope) {
        (scope || document).querySelectorAll('form[data-upload]').forEach(function (form) {
            if (form._z77Upload) return;

            var uploader = new Uploader(form);
            if (!uploader.input || !uploader.list) return;             // not our markup — leave it alone
            form._z77Upload = uploader;
            _forms.push(uploader);

            // From here the script submits, so the fallback button goes.
            form.classList.add('is-scripted');

            uploader.input.addEventListener('change', function () {
                uploader.add(uploader.input.files);
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (uploader.input.files && uploader.input.files.length) uploader.add(uploader.input.files);
            });

            uploader.bindDrop(form);

            // The action-cell shape has no zone of its own: the work area is the target,
            // and the QUEUE moves out there too. A row with a progress bar cannot be read
            // in a 210px cell, and the cell must not grow while files upload — so the
            // list goes where the page offers room (`[data-upload-progress]`), and only
            // the button stays behind (owner 2026-10-10).
            if (uploader.shape === 'cell') {
                var area = document.querySelector('[data-upload-area]')
                    || document.querySelector('.be-shell-col--2, .me-shell__work, main');
                if (area) uploader.bindDrop(area);

                var target = document.querySelector('[data-upload-progress]');
                if (target) {
                    // The tokens live on the component's root, so the queue takes a root
                    // of its own with it — otherwise the rows land outside every
                    // `--z77-up-*` and render unstyled.
                    target.classList.add('z77-upload-queue');
                    target.appendChild(uploader.list);
                    target.appendChild(uploader.summary);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bind(document); });
    } else {
        bind(document);
    }

    // A window or a region brings its own markup (ADR-047): bind what arrives.
    if (_Z77.core && _Z77.core.fetch) {
        _Z77.core.fetch.registerCommand('bind-upload', function (cmd) {
            bind(cmd.selector ? document.querySelector(cmd.selector) : document);
        });
    }

    /**
     * A module registers what only it can produce per file (`data-upload-extra="<name>"`).
     * The function gets `(file, form)` and answers an object of extra form fields — a
     * string or a Blob per entry — or a promise of one.
     */
    function provider(name, fn) { _providers[name] = fn; }

    /**
     * A module registers another wire for big files (`data-upload-transport="<name>"`):
     * `{accepts(file, config), max(config), send(file, ctx)}` — see `_transports` above.
     */
    function transport(name, impl) { _transports[name] = impl; }

    return {
        bind: bind,
        provider: provider,
        transport: transport,
        of: function (form) { return form ? form._z77Upload : null; }
    };
})();
