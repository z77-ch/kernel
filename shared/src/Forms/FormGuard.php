<?php

namespace Z77\Shared\Forms;

use Z77\Core\DI,
    Z77\Core\Session\SessionManager
;

/**
 * Session-based abuse protection for public forms — the generic mechanics every
 * public form needs, extracted from the first consumer (zihlundsee contact
 * form; see docs/03-development/review-email-service-usage.md §1a):
 *
 *   - Time-trap: armTimeTrap() on every form render (idempotent — a re-render
 *     keeps the running window), disarmTimeTrap() when the submit completed;
 *     isTooFast() treats a submit without a render timestamp (direct POST) or
 *     faster than $minSeconds as a bot. The caller decides the reaction
 *     (convention: pretend success, send nothing).
 *   - Rate limit: recordSend() after each successful send; isRateLimited()
 *     caps successful sends per session within a sliding one-hour window.
 *
 * No confirmation flag: the PRG target is a REAL page of its own (a thank-you
 * action), not the form page rendering something else because a session flag
 * says so — see docs/topics/forms.md.
 *
 * Honeypot fields stay on the form DTO (field names differ per form); this
 * guard covers the session-side mechanics only. Not a DI singleton — built per
 * form key via forKey() (placement decision B, like Mailer/DocumentService).
 */
final class FormGuard
{
    private function __construct(
        private string $key,
        private SessionManager $session,
    ) {}

    public static function forKey(string $key): self
    {
        return new self($key, DI::getSessionManager());
    }

    /**
     * Arms the time-trap window — call on every render of the form. IDEMPOTENT:
     * a running window is kept, so the re-render after a rejected submit
     * (validation error, CSRF error) does not restart the clock. Restarting it
     * would classify the very next correction as a bot: fixing one field and
     * pressing Send takes well under $minSeconds, and the caller's reaction to
     * "too fast" is a silent fake success — the message would be dropped while
     * the visitor reads a thank-you. The window measures "time since the form
     * was first handed out", which is what the trap is about.
     */
    public function armTimeTrap(): void
    {
        if ((int) $this->session->get($this->sessionKey('renderedAt'), 0) === 0) {
            $this->session->set($this->sessionKey('renderedAt'), time());
        }
    }

    /**
     * Ends the current form cycle — call after a completed submit (real or
     * bot-faked) so the next render arms a fresh window instead of inheriting
     * the finished one.
     */
    public function disarmTimeTrap(): void
    {
        $this->session->set($this->sessionKey('renderedAt'), 0);
    }

    /** True when the submit came without a render timestamp or too fast after it. */
    public function isTooFast(int $minSeconds = 3): bool
    {
        $renderedAt = (int) $this->session->get($this->sessionKey('renderedAt'), 0);
        return $renderedAt === 0 || (time() - $renderedAt) < $minSeconds;
    }

    /** True when the per-session send limit within the last hour is reached. */
    public function isRateLimited(int $maxPerHour = 3): bool
    {
        return count($this->recentSends()) >= $maxPerHour;
    }

    /**
     * How many sends THIS BROWSER got through on this form within the last
     * hour.
     *
     * The point of exposing the number next to {@see self::isRateLimited()} is
     * that the two carry different knowledge. The verdict is about the limit —
     * on a silent form, saying anything about it would tell a stranger that
     * this submit was treated differently from the last. The COUNT is about
     * what this visitor just did, which the visitor already knows: it names no
     * account, no address and no limit, so a page may act on it without
     * becoming an oracle.
     *
     * ⚠️ It saturates at the limit — past it nothing is recorded any more
     * ({@see PublicFormHandler} records only a successful send). Read it as
     * «asked once» vs. «asked again», never as a total.
     */
    public function sendCount(): int
    {
        return count($this->recentSends());
    }

    /** Registers a successful send for the rate-limit window. */
    public function recordSend(): void
    {
        $sends   = $this->recentSends();
        $sends[] = time();
        $this->session->set($this->sessionKey('sends'), $sends);
    }

    /** @return list<int> send timestamps within the last hour (pruned) */
    private function recentSends(): array
    {
        $cutoff = time() - 3600;
        return array_values(array_filter(
            (array) $this->session->get($this->sessionKey('sends'), []),
            static fn ($t) => (int) $t > $cutoff
        ));
    }

    private function sessionKey(string $name): string
    {
        return 'formGuard.' . $this->key . '.' . $name;
    }
}
