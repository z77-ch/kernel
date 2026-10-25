<?php
namespace Z77\Core\Kibot;

use Z77\Core\Http\Security\CsrfService,
    Z77\Core\Session\SessionManager,
    Z77\KiBot\Guard,
    Z77\KiBot\SessionStore
;

/**
 * KiBot's per-visitor state over the z77 session (docs/topics/kibot.md).
 *
 * The CSRF token is z77's own: Guard reads it under {@see Guard::TOKEN_KEY} and
 * gets {@see CsrfService::getToken()}, so the widget sends ONE token that
 * satisfies both AccessGuard (X-CSRF-Token header on every fetch POST) and
 * Guard (body field). Guard::issueToken() is never called in z77 — a write to
 * the token key is a wiring error and throws instead of forking a second token.
 * Every other key (rate counter, topics of the last answer) lives in the z77
 * session under KiBot's own `kibot*` names.
 */
final class KibotSession implements SessionStore
{
    public function __construct(
        private SessionManager $session,
        private CsrfService $csrf,
    ) {}

    public function get(string $key): mixed
    {
        return $key === Guard::TOKEN_KEY ? $this->csrf->getToken() : $this->session->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        if ($key === Guard::TOKEN_KEY) {
            throw new \LogicException('KiBot in z77 uses the z77 CSRF token (CsrfService); Guard::issueToken() must not be called.');
        }
        $this->session->set($key, $value);
    }
}
