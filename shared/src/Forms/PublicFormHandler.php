<?php

namespace Z77\Shared\Forms;

use Z77\Core\DI;
use Z77\Shared\GeoIp\CountryLookup;

/**
 * The submit flow of a public form — CSRF, bot checks, validation, rate limit,
 * send and the PRG hand-off, in one place (see
 * docs/03-development/public-form-bauplan.md). A controller action shrinks to
 * "process, then render":
 *
 *   $form = PublicFormHandler::create(new ContactFormDefinition());
 *   if ($form->process()) {
 *       return $this->redirect(localizedUrl('/kontakt/danke'));   // PRG
 *   }
 *   return $this->html(['pageTitle' => 'Kontakt'] + $form->viewContext());
 *
 * The order of checks is deliberate and must not change:
 *
 *   CSRF invalid              → friendly re-render (never a reject — the UX
 *                               reason for the in-action check instead of the
 *                               #[Csrf] attribute, see docs/topics/security.md)
 *   honeypot OR too fast      → pretend success (PRG), send nothing
 *   country blocked           → generic error, or the bot shape when the geo
 *                               guard runs silent ({@see self::withGeoGuard()})
 *   validation failed         → field errors + top banner, values kept
 *   rate limited              → generic error
 *   send ok                   → recordSend + PRG
 *   send failed               → generic error (a transport/config problem must
 *                               never escalate to a 500 on a public form)
 *
 * Responses stay with the controller (ADR-003): process() returns a bool —
 * "the caller must now redirect" — not a Response object.
 *
 * The handler RETURNS STATE and does nothing else. Everything it produces
 * reaches the page through viewContext(); it renders nothing, redirects
 * nothing, and pushes no messages of its own. What the visitor sees after a
 * successful submit is the controller's business: it redirects to a thank-you
 * PAGE (so "was this sent?" is answered by the URL, not by hidden session
 * state) and pushes a flash there if it wants one:
 *
 *   if ($form->process()) {
 *       $this->messageService->pushFlashAfterRedirect('success', t('form.flash.sent'));
 *       return $this->redirect(localizedUrl('/kontakt/danke'));
 *   }
 *
 * Its cross-request effects are the FormGuard session state (bot defence, not
 * UI) and — only where {@see self::withGeoGuard()} is on — one {@see FormLog}
 * line per submit. The log never throws; a full disk costs a line, never a
 * submit.
 *
 * Not a DI singleton — built per form like Mailer::create() / FormGuard::forKey().
 */
final class PublicFormHandler
{
    private FormGuard $guard;
    private PublicForm $form;

    /** @var array<string,string> field name => message */
    private array $errors = [];

    private string $formError = '';
    private bool $processed   = false;

    private string $csrfErrorKey       = 'form.error.csrf';
    private string $sendErrorKey       = 'form.error.send';
    private string $validationErrorKey = 'form.error.check';

    private int  $rateLimitPerHour = 3;
    private bool $rateLimitSilent  = false;

    private bool $geoGuard  = false;
    private bool $geoSilent = false;

    /** @var array<string, scalar|null> facts pinned to every log line */
    private array $geoExtra = [];

    /** @var array{0: ?string, 1: ?string}|null memoized [ip, country] */
    private ?array $origin = null;

    /** @var \Closure(string, PublicForm):void|null */
    private ?\Closure $observer = null;

    private function __construct(private FormDefinition $definition)
    {
        $this->guard = FormGuard::forKey($definition->guardKey());
        $this->form  = PublicForm::blank($definition);
    }

    public static function create(FormDefinition $definition): self
    {
        return new self($definition);
    }

    /**
     * Overrides the form-level message keys (translation keys). These are texts
     * the handler RETURNS through viewContext() — not messages it emits
     * somewhere on its own.
     */
    public function withMessageKeys(
        ?string $csrfError = null,
        ?string $sendError = null,
        ?string $validationError = null,
    ): self {
        $this->csrfErrorKey       = $csrfError ?? $this->csrfErrorKey;
        $this->sendErrorKey       = $sendError ?? $this->sendErrorKey;
        $this->validationErrorKey = $validationError ?? $this->validationErrorKey;

        return $this;
    }

    /**
     * Adjusts the per-session send limit (successful sends within a sliding
     * hour, {@see FormGuard::isRateLimited()}).
     *
     * `$silent` decides what hitting it looks like: by default the visitor
     * gets the send-error banner — right for a contact form, where saying
     * «not sent» is the honest answer. A form whose whole page must stay
     * indistinguishable (the member login: every submit lands on the waiting
     * page, MEM-005) sets `silent: true` instead — the submit then behaves
     * like the bot path: nothing is sent, the caller redirects as if it were.
     */
    public function withRateLimit(int $maxPerHour, bool $silent = false): self
    {
        $this->rateLimitPerHour = $maxPerHour;
        $this->rateLimitSilent  = $silent;

        return $this;
    }

    /**
     * Switches the country rule AND the form log on — one switch, not two:
     * a form that refuses by origin must leave the evidence that justifies
     * the refusal, and a form that only wants the evidence gets the rule as
     * its consequence (the list is empty until an operator fills it, so the
     * rule starts as a no-op).
     *
     * The gate reads {@see CountryBlocklist} (installation data, edited on
     * the backend's form-log page) and FAILS OPEN: an empty list, an unknown
     * country — no database, private range, broken file — never blocks. The
     * refused submit shows the generic send error, because a country rule
     * can hit a real customer and the customer must be able to notice;
     * `$silent: true` is for forms whose page must stay indistinguishable
     * (the member login, MEM-005) — the hit then behaves like the bot path.
     *
     * ⚠️ THE COUNTRY DATA DOES NOT COME WITH THIS SWITCH. The GeoLite
     * database is fetched per installation and by hand: a MaxMind account,
     * the licence key in `config/geoip.inc.php` (machine-local, gitignored,
     * NOT deployed — it must exist on every server), and the `geoip-update`
     * job active, which also performs the initial download. Without that,
     * this guard runs but every country reads as unknown — the rule is
     * silently inert and only the backend page says so. Setup and licence
     * duties: docs/topics/geoip.md.
     *
     * `$extra` pins named TECHNICAL facts to every log line of this handler
     * (e.g. `['origin' => $via]` — which offer link led here). Never put an
     * identifying value in it; that is what the definition's
     * {@see FormDefinition::identityField()} opt-in exists for.
     *
     * @param array<string, scalar|null> $extra
     */
    public function withGeoGuard(bool $silent = false, array $extra = []): self
    {
        $this->geoGuard  = true;
        $this->geoSilent = $silent;
        $this->geoExtra  = $extra;

        return $this;
    }

    /**
     * Tells an observer how each submit ended — the seam a project hangs a
     * log on. One of {@see self::OUTCOME_*} plus the parsed form.
     *
     * It exists because the OUTCOME is knowable only in here: `process()`
     * returns one bool for «real success» and «bot pretending to be one»
     * (that indistinguishability is the point), so no caller can tell the two
     * apart from the outside. An observer sees the difference SERVER-SIDE,
     * where telling it changes nothing for the visitor.
     *
     * ⚠️ The observer must not change what the visitor gets: it is called
     * after the decision, its return value is ignored, and anything it throws
     * is swallowed. A broken log may never cost a registration.
     *
     * @param callable(string, PublicForm):void $observer
     */
    public function withObserver(callable $observer): self
    {
        $this->observer = \Closure::fromCallable($observer);

        return $this;
    }

    /** The submit was accepted and the project's handler ran. */
    public const OUTCOME_SENT = 'sent';
    /** Honeypot filled or submitted faster than a human reads the form. */
    public const OUTCOME_BOT = 'bot';
    /** Origin country is on the blocklist — nothing was sent. */
    public const OUTCOME_GEO = 'geo';
    /** Over the per-session hourly limit — nothing was sent. */
    public const OUTCOME_LIMITED = 'limited';
    /** Field validation failed; the form is rendered again with errors. */
    public const OUTCOME_INVALID = 'invalid';
    /** Missing or stale CSRF token. */
    public const OUTCOME_CSRF = 'csrf';
    /** The project's handler was reached but reported failure. */
    public const OUTCOME_FAILED = 'failed';

    private function observe(string $outcome): void
    {
        if ($this->observer === null) {
            return;
        }

        try {
            ($this->observer)($outcome, $this->form);
        } catch (\Throwable) {
            // Deliberately silent: an observer is bookkeeping. Letting it
            // break the form would turn a logging fault into an outage.
        }
    }

    /**
     * Every outcome passes through here: the observer is told, and — when the
     * geo guard is on — the {@see FormLog} line is written. The line comes
     * AFTER the dispatch of the same submit, so a {@see FormLog::note()} set
     * by the project's flow lands on the row it belongs to.
     */
    private function record(string $outcome): void
    {
        $this->observe($outcome);

        if (!$this->geoGuard) {
            return;
        }

        [$ip, $country] = $this->origin();

        $identityField = $this->definition->identityField();
        $identity      = $identityField !== null
            ? (string) $this->form->get($identityField)
            : null;

        // FormLog::write() never throws — a full disk costs the line, not
        // the submit.
        FormLog::write(
            $this->definition->guardKey(),
            $outcome,
            $ip,
            $country,
            $identity,
            $this->geoExtra,
        );
    }

    /**
     * True when the geo guard is on and this origin's country is on the
     * blocklist.
     *
     * ⚠️ Fails OPEN, always. An empty list means the rule is off (where every
     * installation starts); an unknown country — no database installed, a
     * private address, an unassigned range, a broken file — reads as null,
     * and null never blocks. Blocking on «we do not know» would turn a
     * missing optional file into a total outage of every guarded form, which
     * is exactly the failure mode the whole GeoIP layer was built to avoid.
     *
     * The empty list short-circuits the COMPARISON only — the lookup itself
     * still happens (lazily, in {@see self::origin()}) because the log line
     * needs the country either way: the log is the evidence a block is later
     * decided from, and the normal case starts with an empty list.
     */
    private function isGeoBlocked(): bool
    {
        if (!$this->geoGuard) {
            return false;
        }

        $blocked = CountryBlocklist::codes();
        if ($blocked === []) {
            return false;
        }

        [, $country] = $this->origin();

        return $country !== null && in_array($country, $blocked, true);
    }

    /**
     * The client address and its country — read and resolved ONCE per
     * submit, shared by the gate and the log line. The log is the evidence
     * for the gate's decision; two independent reads could disagree, and a
     * log that shows a different country than the one the gate blocked on
     * is worse than none (review finding F4).
     *
     * ⚠️ REMOTE_ADDR, never X-Forwarded-For or Client-IP: those are set by
     * whoever sends the request — a country derived from them is whatever
     * the sender wants it to be. Behind a real reverse proxy the trusted-
     * header handling belongs HERE, deliberately (single call site); a
     * `Request` client-ip seam is a separate kernel change (forms.md
     * pending).
     *
     * @return array{0: ?string, 1: ?string} [ip, country]
     */
    private function origin(): array
    {
        if ($this->origin !== null) {
            return $this->origin;
        }

        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $ip = $ip !== '' && @inet_pton($ip) !== false ? $ip : null;

        return $this->origin = [$ip, $ip === null ? null : CountryLookup::of($ip)];
    }

    /**
     * Runs the flow for the current request.
     *
     * @param callable(PublicForm):bool|null $onValid Called with the validated
     *     form INSTEAD of the default sendForm() — this is where the project
     *     gives the go-ahead (return true = handled → PRG; false = generic
     *     error). Use it for dynamic recipients (EmailService::send()) or for
     *     anything else a valid submit should trigger.
     *
     * @return bool true → the caller must redirect (PRG): a real success or a
     *     bot pretending to be one. false → render the form via viewContext().
     */
    public function process(?callable $onValid = null): bool
    {
        $request = DI::getRequest();

        if (!$request->isReadMethod()) {
            $this->form = PublicForm::fromPost($this->definition, $request->getPostParameters());

            if (!DI::getCsrfService()->validate((string) $request->getPostParameter('csrf_token'))) {
                $this->formError = $this->translate($this->csrfErrorKey);
                $this->record(self::OUTCOME_CSRF);
            } elseif ($this->form->isHoneypotTripped() || $this->guard->isTooFast()) {
                // Bot: pretend success, send nothing. Indistinguishable from the
                // real path below, which is the point.
                $this->guard->disarmTimeTrap();
                $this->record(self::OUTCOME_BOT);
                return true;
            } elseif ($this->isGeoBlocked()) {
                // After the bot trap (which is free) and before validation and
                // the throttle: a flat refusal that should consume no throttle
                // slot on its way out.
                if ($this->geoSilent) {
                    // Same shape as the bot path above: the page must not
                    // reveal that this submit was treated differently.
                    $this->guard->disarmTimeTrap();
                    $this->record(self::OUTCOME_GEO);
                    return true;
                }
                // Visible by default: a country rule can hit a real customer,
                // and a silent drop would leave them waiting for a mail that
                // is never coming.
                $this->formError = $this->translate($this->sendErrorKey);
                $this->record(self::OUTCOME_GEO);
            } else {
                $validator = new PublicFormValidator($this->form);

                if (!$validator->isValid()) {
                    // Per-field errors + a form-level banner, so the reason is
                    // visible at the top without scrolling to the failed field.
                    $this->errors    = $validator->getFieldErrors();
                    $this->formError = $this->translate($this->validationErrorKey);
                    $this->record(self::OUTCOME_INVALID);
                } elseif ($this->guard->isRateLimited($this->rateLimitPerHour)) {
                    if ($this->rateLimitSilent) {
                        // Same shape as the bot path above: send nothing, let
                        // the caller redirect. The page must not reveal that
                        // this submit was treated differently.
                        $this->guard->disarmTimeTrap();
                        $this->record(self::OUTCOME_LIMITED);
                        return true;
                    }
                    $this->formError = $this->translate($this->sendErrorKey);
                    $this->record(self::OUTCOME_LIMITED);
                } elseif ($this->dispatch($onValid)) {
                    $this->guard->recordSend();
                    // Cycle closed: the next form render arms a new window.
                    $this->guard->disarmTimeTrap();
                    $this->record(self::OUTCOME_SENT);
                    return true;
                } else {
                    $this->formError = $this->translate($this->sendErrorKey);
                    $this->record(self::OUTCOME_FAILED);
                }
            }
        }

        // Time-trap baseline: armed on the first render of a form cycle and
        // KEPT across re-renders (FormGuard::armTimeTrap() is idempotent) — a
        // corrected resubmit must not be measured against the error page it
        // came from. A completed submit disarms it (see the two return-true
        // paths above), so the next form render starts a fresh window.
        $this->guard->armTimeTrap();
        $this->processed = true;

        return false;
    }

    /** The validated submit: project callback if given, else the form mail. */
    private function dispatch(?callable $onValid): bool
    {
        if ($onValid !== null) {
            return (bool) $onValid($this->form);
        }

        $replyToField = $this->definition->replyToField();
        $routeField   = $this->definition->routeField();

        return DI::getEmailService()->sendForm(
            $this->definition->formKey(),
            ['form' => $this->form],
            $replyToField !== null ? $this->form->get($replyToField) : null,
            $routeField !== null ? $this->form->get($routeField) : null,
        );
    }

    /**
     * Template state — merge into the controller's html() context. Same keys the
     * hand-written forms used, so a project template keeps its shape.
     *
     * @return array{form:PublicForm,fields:array,errors:array,formError:string,checkUrl:string}
     */
    public function viewContext(): array
    {
        if (!$this->processed) {
            throw new \RuntimeException('PublicFormHandler::process() must run before viewContext().');
        }

        return [
            'form'      => $this->form,
            'fields'    => $this->definition->normalizedFields(),
            'errors'    => $this->errors,
            'formError' => $this->formError,
            'checkUrl'  => $this->checkUrl(),
        ];
    }

    public function form(): PublicForm
    {
        return $this->form;
    }

    /**
     * Blur-check endpoint: the definition's explicit value, else derived from
     * the current route (`/{module}/{group}/{controller}/check`).
     */
    private function checkUrl(): string
    {
        $explicit = $this->definition->checkUrl();
        if ($explicit !== null) {
            return $explicit;
        }

        // The Request getters already hold the cleaned URL segments — no name
        // transformation happens here, just assembly.
        $request = DI::getRequest();

        return '/' . implode('/', [
            $request->getModule(),
            $request->getGroup(),
            $request->getController(),
            'check',
        ]);
    }

    private function translate(string $key): string
    {
        return DI::getTranslator()->t($key);
    }
}
