<?php
// Default Auth Config — installation-wide authentication policy.
// Override per installation via composer.json extra `core-auth`; the installer
// merges it and writes the runtime file to config/client/auth.inc.php.
return [
    // Password strength tier. Allowed values + their min length and block/nag
    // behaviour are defined ENTIRELY in Z77\Shared\Auth\PasswordTier (the single
    // source of truth — do not restate them here, they would drift). Bound to the
    // enum (not a bare string) so a typo here is a fatal error at load time, never
    // a silent value. See docs/topics/security.md (PWD-POLICY-001).
    'passwordTier' => \Z77\Shared\Auth\PasswordTier::Strong->value,
    // Login brute-force limit (SEC-004): failed attempts per 15 minutes, per username+IP and per
    // IP. Bound to the class that also supplies the fallback for an existing installation, whose
    // seed-once file has no such keys. See docs/topics/security.md.
    'loginFailuresPerUsernameIp' => \Z77\Shared\Auth\LoginThrottle::FAILURES_PER_USERNAME_IP,
    'loginFailuresPerIp'         => \Z77\Shared\Auth\LoginThrottle::FAILURES_PER_IP,
    // Who is told when the backend refuses every 2FA code because its counters cannot be written
    // (login.md «code refused»). Empty: nobody, the log line stays. A seed-once file has no such key.
    'codeLockAlertEmail'         => '',
];
