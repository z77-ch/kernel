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
 *   - Time-trap: armTimeTrap() on every form render; isTooFast() treats a
 *     submit without a render timestamp (direct POST) or faster than
 *     $minSeconds as a bot. The caller decides the reaction (convention:
 *     pretend success, send nothing).
 *   - Rate limit: recordSend() after each successful send; isRateLimited()
 *     caps successful sends per session within a sliding one-hour window.
 *   - PRG confirmation flag: markSent() before the redirect, consumeSent()
 *     on the next GET (returns true once, then resets).
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

    /** Arms/re-arms the time-trap window — call on every render of the form. */
    public function armTimeTrap(): void
    {
        $this->session->set($this->sessionKey('renderedAt'), time());
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

    /** Registers a successful send for the rate-limit window. */
    public function recordSend(): void
    {
        $sends   = $this->recentSends();
        $sends[] = time();
        $this->session->set($this->sessionKey('sends'), $sends);
    }

    /** Sets the PRG confirmation flag — call before the redirect after success. */
    public function markSent(): void
    {
        $this->session->set($this->sessionKey('sent'), true);
    }

    /** Returns the PRG flag once and resets it (the next GET shows the confirmation). */
    public function consumeSent(): bool
    {
        $sent = (bool) $this->session->get($this->sessionKey('sent'), false);
        if ($sent) {
            $this->session->set($this->sessionKey('sent'), false);
        }
        return $sent;
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
