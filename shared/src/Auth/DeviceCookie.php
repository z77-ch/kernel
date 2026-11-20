<?php

namespace Z77\Shared\Auth;

/**
 * The carrier of a «stay signed in on this device» token: one cookie, hardened like the session
 * cookie (HttpOnly, SameSite=Lax, Secure on HTTPS) but long-lived. Each door has its own name and
 * its own store; what the value means is the door's business (member: DeviceKeys, backend:
 * RememberedDevices).
 *
 * The kernel has no cookie abstraction, so this is the one place that touches
 * setcookie()/$_COOKIE for such a token. Writes update $_COOKIE too: the same request must see
 * what it just handed out (and a harness runs without headers at all).
 *
 * ⚠️ The value is a credential: never log it, never render it.
 */
final class DeviceCookie
{
    public function __construct(private string $name, private string $path = '/')
    {
    }

    public function read(): ?string
    {
        $value = $_COOKIE[$this->name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function write(string $value, int $ttlSeconds, ?int $now = null): void
    {
        $_COOKIE[$this->name] = $value;
        $this->send($value, ($now ?? time()) + $ttlSeconds);
    }

    public function clear(): void
    {
        unset($_COOKIE[$this->name]);
        $this->send('', 1);
    }

    private function send(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        setcookie($this->name, $value, [
            'expires'  => $expires,
            'path'     => $this->path,
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
