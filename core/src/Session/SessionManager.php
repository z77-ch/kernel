<?php

namespace Z77\Core\Session;

/**
 * SessionManager
 *
 * Zentraler Manager für Sessions, kapselt session_start und Session-Operationen.
 * Prüft auf bereits gesendete Header und wirft bei Fehlern kontrollierte Exceptions.
 * Später kann ein Custom SessionHandler registriert werden.
 */
class SessionManager
{
    private bool $started = false;
    private ?SessionHandler $sessionHandler = null;

    /**
     * Konstruktor
     * Startet die Session sofort.
     */
    public function __construct()
    {
        $this->startSession();
    }

    /**
     * Startet die Session, wenn sie noch nicht gestartet wurde.
     * Prüft headers_sent() und wirft Exception, wenn Header bereits gesendet.
     */
    private function startSession(): void
    {
        if ($this->started) {
            return;
        }

        if (php_sapi_name() === 'cli') {
            // CLI-Modus: keine Session notwendig
            $this->started = true;
            return;
        }

        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Cannot start session: headers already sent in $file on line $line"
            );
        }
        // Custom Handler registrieren
        if ($this->sessionHandler !== null) {
            session_set_save_handler($this->handler, true);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->started = true;
    }

    /**
     * Wert in Session speichern
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Wert aus Session lesen
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Prüfen, ob ein Key existiert
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Wert aus Session entfernen
     */
    public function remove(string $key): void
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Ganze Session leeren
     */
    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Optional: später Custom SessionHandler registrieren
     */
    public function setHandler(\SessionHandlerInterface $handler): void
    {
        if ($this->started) {
            throw new \RuntimeException("Cannot set session handler after session has started");
        }

        $this->sessionHandler = $handler;
    }
}
