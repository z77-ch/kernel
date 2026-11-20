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
    // How long a backend login lasts (LOGIN-SESSION-001), enforced by the framework per request,
    // not by PHP's session garbage collection. Bound to the class that reads them and supplies
    // the fallback for a seed-once file without the keys. See docs/topics/login.md.
    //   sessionIdleMinutes    signed out after this long without a request
    //   sessionLifetimeHours  signed out this long after the login, in any case
    'sessionIdleMinutes'   => \Z77\Shared\Auth\SessionPolicy::IDLE_MINUTES,
    'sessionLifetimeHours' => \Z77\Shared\Auth\SessionPolicy::LIFETIME_HOURS,
    // «Auf diesem Gerät angemeldet bleiben» on the backend login. false: the checkbox is not
    // rendered and no device cookie is accepted. A remembered device signs in without the
    // password for rememberDeviceDays after its last full login; with
    // rememberSkipsSecondFactor it also skips the TOTP code (the device passed it when it was
    // remembered), with false the code is asked again every time.
    'rememberDevice'            => \Z77\Shared\Auth\SessionPolicy::REMEMBER_DEVICE,
    'rememberDeviceDays'        => \Z77\Shared\Auth\SessionPolicy::REMEMBER_DEVICE_DAYS,
    'rememberSkipsSecondFactor' => \Z77\Shared\Auth\SessionPolicy::REMEMBER_SKIPS_SECOND_FACTOR,
];
