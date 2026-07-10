<?php

namespace Z77\Shared\Mail;

/**
 * A minimal, dependency-free SMTP client (DMS Phase 6, OPEN-5: own build, no Composer
 * mail dependency). Conducts the standard conversation — greeting → `EHLO` → optional
 * `STARTTLS` + re-`EHLO` → optional `AUTH LOGIN` → `MAIL FROM` → `RCPT TO`* → `DATA` →
 * `QUIT` — over a stream socket, asserting the expected reply code at each step.
 *
 * Encryption modes:
 *   - `ssl`  — implicit TLS from connect (SMTPS, typically port 465: `ssl://host:port`)
 *   - `tls`  — plain connect, then `STARTTLS` upgrade (typically port 587)
 *   - `none` — plaintext (local relays / tests only)
 *
 * The DATA payload is SMTP dot-stuffed here (a line starting with `.` is doubled), so the
 * {@see MimeMessage} builder stays a pure MIME concern. Bare `<addr>` is used for the
 * envelope, never user-supplied header text — combined with {@see Message}'s CR/LF
 * rejection this closes the command-injection surface.
 */
final class SmtpTransport implements MailTransport
{
    private const CRLF = "\r\n";

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $encryption = 'tls', // 'tls' | 'ssl' | 'none'
        private string $username = '',
        private string $password = '',
        private int $timeout = 15,
        private string $heloHost = 'localhost',
    ) {}

    public function send(string $sender, array $recipients, string $data): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('SMTP host is not configured.');
        }
        if ($recipients === []) {
            throw new \RuntimeException('SMTP: no recipients.');
        }

        $socket = $this->connect();
        try {
            $this->expect($socket, 220);
            $this->ehlo($socket);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP: STARTTLS negotiation failed.');
                }
                $this->ehlo($socket); // re-EHLO over the encrypted channel
            }

            if ($this->username !== '') {
                $this->authLogin($socket);
            }

            $this->command($socket, 'MAIL FROM:<' . $sender . '>', 250);
            foreach ($recipients as $recipient) {
                $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command($socket, 'DATA', 354);
            fwrite($socket, $this->dotStuff($data) . self::CRLF . '.' . self::CRLF);
            $this->expect($socket, 250);

            // Best-effort polite close; ignore the reply.
            @fwrite($socket, 'QUIT' . self::CRLF);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @return resource */
    private function connect()
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;

        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new \RuntimeException("SMTP: cannot connect to {$remote} ({$errno} {$errstr}).");
        }
        stream_set_timeout($socket, $this->timeout);
        return $socket;
    }

    /** @param resource $socket */
    private function ehlo($socket): void
    {
        $this->command($socket, 'EHLO ' . $this->heloHost, 250);
    }

    /** @param resource $socket */
    private function authLogin($socket): void
    {
        $this->command($socket, 'AUTH LOGIN', 334);
        $this->command($socket, base64_encode($this->username), 334);
        $this->command($socket, base64_encode($this->password), 235);
    }

    /**
     * Write a command and assert the reply code.
     *
     * @param resource $socket
     * @param int|list<int> $expected
     */
    private function command($socket, string $command, int|array $expected): void
    {
        fwrite($socket, $command . self::CRLF);
        $this->expect($socket, $expected);
    }

    /**
     * Read a (possibly multi-line) reply and assert its code.
     *
     * @param resource $socket
     * @param int|list<int> $expected
     */
    private function expect($socket, int|array $expected): void
    {
        $expected = (array) $expected;
        $line     = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException(
                    'SMTP: connection lost while awaiting reply' . (($meta['timed_out'] ?? false) ? ' (timeout).' : '.')
                );
            }
            // Continuation lines have a '-' as the 4th char ("250-..."); the final line a space.
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(
                'SMTP: unexpected reply ' . trim($line) . ' (expected ' . implode('/', $expected) . ').'
            );
        }
    }

    /** Doubles a leading dot on any line so it is not read as the DATA terminator. */
    private function dotStuff(string $data): string
    {
        $data = str_replace("\r\n", "\n", $data);
        $lines = explode("\n", $data);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);
        return implode(self::CRLF, $lines);
    }
}
