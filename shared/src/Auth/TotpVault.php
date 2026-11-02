<?php

namespace Z77\Shared\Auth;

/**
 * Encryption for the TOTP secret at rest (B8 spec: «verschluesselt abgelegt»,
 * never plaintext in the account store). AES-256-GCM via OpenSSL; the key is
 * a 32-byte file the owner names — one per door (the member module's under
 * data/framework/member/, the backend's under data/framework/auth/), outside the
 * webroot, created by the first encrypt() when absent, never committed (data/ is
 * untracked) and never deployed as code. Losing the key file means every account
 * of that door has to be reset and sets up anew.
 *
 * Ciphertext format: base64(iv[12] . tag[16] . ct). decrypt() answers null
 * for anything that does not authenticate, and when the key file is missing or
 * damaged — never a wrong secret. What null means is the caller's: the member's
 * setup starts over, the backend login refuses the code. A damaged key file makes
 * encrypt() throw.
 */
final class TotpVault
{
    public function __construct(private string $keyFile)
    {
    }

    /** @throws \RuntimeException when the key file is damaged or cannot be created: nothing is overwritten */
    public function encrypt(string $plain): string
    {
        $key = $this->readKey() ?? $this->createKey();
        if ($key === null) {
            throw new \RuntimeException('TOTP key file unusable: ' . $this->keyFile);
        }

        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('TOTP secret encryption failed');
        }

        return base64_encode($iv . $tag . $ct);
    }

    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) { // 12 iv + 16 tag + ≥1 ct
            return null;
        }

        $key = $this->readKey();
        if ($key === null) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plain === false ? null : $plain;
    }

    /**
     * The key, or null. ⚠️ A file of the wrong length is never replaced: one stray newline from an
     * editor or a text-mode transfer must stay repairable, a new key would make every stored secret
     * of this door unreadable for good. A file still empty after the wait counts as damaged: the
     * empty moment is another process between creating and writing it, {@see createKey()}.
     */
    private function readKey(): ?string
    {
        for ($attempt = 0; $attempt < 10 && is_file($this->keyFile); $attempt++) {
            $key = (string) @file_get_contents($this->keyFile);
            if (strlen($key) === 32) {
                return $key;
            }
            if ($key !== '') {
                break;
            }
            usleep(20000);
        }
        if (is_file($this->keyFile)) {
            error_log('TotpVault: key file is damaged and was left as it is: ' . $this->keyFile);
        }

        return null;
    }

    /**
     * Only when the file is absent: `x` lets exactly one process create it, the loser reads what
     * the winner wrote. Never called from {@see decrypt()}: a read creates nothing.
     */
    private function createKey(): ?string
    {
        $dir = dirname($this->keyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($this->keyFile, 'x');
        if ($handle === false) {
            return $this->readKey();
        }

        $key = random_bytes(32);
        fwrite($handle, $key);
        fclose($handle);
        @chmod($this->keyFile, 0600);

        return $key;
    }
}
