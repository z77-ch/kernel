<?php

namespace Z77\Core\Http\Security;

use Z77\Core\Session\SessionManager;

class CsrfService
{
    private const SESSION_KEY = 'csrf.token';

    public function __construct(private SessionManager $sessionManager) {}

    public function getToken(): string
    {
        if (!$this->sessionManager->has(self::SESSION_KEY)) {
            $this->sessionManager->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
        }

        return $this->sessionManager->get(self::SESSION_KEY);
    }

    public function validate(string $token): bool
    {
        // A session without a token reads as '', and hash_equals('', '') is true (SEC-007).
        if ($token === '') {
            return false;
        }

        $stored = $this->sessionManager->get(self::SESSION_KEY, '');

        return hash_equals($stored, $token);
    }

    public function generateEntityToken(string $context, int|string $id): string
    {
        return hash_hmac('sha256', $context . ':' . $id, $this->getToken());
    }

    public function validateEntityToken(string $token, string $context, int|string $id): bool
    {
        return hash_equals($this->generateEntityToken($context, $id), $token);
    }
}
