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
     * Starts the session at once.
     *
     * @param ?string $saveDir       the installation's own directory for the session files
     *                               (absolute); null leaves the host's `session.save_path`
     * @param ?int    $gcMaxLifetime seconds after which an untouched session file may be
     *                               collected; null leaves the host's value
     *
     * ⚠️ Both come from Bootstrap (SessionPolicy, login.md LOGIN-SESSION-001). Without them the
     * files sit in the host's shared session directory and die by ITS garbage collection —
     * on shared hosting after a few minutes, whatever this installation wants.
     */
    public function __construct(private ?string $saveDir = null, private ?int $gcMaxLifetime = null)
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
            // Explicit cookie hardening — never rely on the host's php.ini
            // (PHP's default for samesite is EMPTY): HttpOnly (no JS access),
            // SameSite=Lax (CSRF defense-in-depth on top of CsrfService),
            // Secure on HTTPS (same detection as Request::parseUrl), and
            // strict mode so uninitialized session ids are rejected
            // (session fixation).
            ini_set('session.use_strict_mode', '1');
            $this->ownStorage();
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $this->started = true;
    }

    /**
     * The session files in the installation's own directory, with its own collection.
     *
     * - Only for PHP's `files` handler: with another one (redis, memcached) `save_path` is not
     *   a directory, and the host's store has its own expiry.
     * - The collector is switched ON explicitly: distributions that clean the default directory
     *   by cron ship `session.gc_probability = 0`, and that cron never looks into a custom path.
     *   One request in a hundred sweeps the directory.
     * - A directory that cannot be created (a dangling `var/lib` after a release switch) must not
     *   lock everybody out: the host's path stays, with a log line.
     */
    private function ownStorage(): void
    {
        if ($this->gcMaxLifetime !== null) {
            ini_set('session.gc_maxlifetime', (string) $this->gcMaxLifetime);
        }
        if ($this->saveDir === null || ini_get('session.save_handler') !== 'files') {
            return;
        }

        if (!self::prepareSaveDir($this->saveDir)) {
            error_log('SessionManager: session directory not usable, the host\'s session.save_path stays — ' . $this->saveDir);
            return;
        }

        ini_set('session.save_path', $this->saveDir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    /**
     * Creates the directory for the owner only (0700) and puts the deny-all `.htaccess` into it:
     * it is outside the document root, the file is the alarm if a link ever points there.
     */
    private static function prepareSaveDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            $parent = dirname($dir);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                return false;
            }
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                return false;
            }
            $deny = __DIR__ . '/../../res/htaccess-deny';
            if (is_file($deny)) {
                @copy($deny, $dir . '/.htaccess');
            }
        }

        return is_writable($dir);
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
