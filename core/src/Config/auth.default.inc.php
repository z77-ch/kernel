<?php
// Default Auth Config — installation-wide authentication policy.
// Override per installation via composer.json extra `core-auth`; the installer
// merges it and writes the runtime file to config/auth.inc.php.
return [
    // Password strength tier. Allowed values + their min length and block/nag
    // behaviour are defined ENTIRELY in Z77\Shared\Auth\PasswordTier (the single
    // source of truth — do not restate them here, they would drift). Bound to the
    // enum (not a bare string) so a typo here is a fatal error at load time, never
    // a silent value. See docs/topics/security.md (PWD-POLICY-001).
    'passwordTier' => \Z77\Shared\Auth\PasswordTier::Strong->value,
];
