<?php
//
// Installer defaults for config/mail.inc.php (seed-once — see Install::writeMailConfig()).
// Overridable via composer.json extra key "core-mail".
//
// transport 'mail' = PHP mail() over the local MTA (shared hosting, no credentials
// needed); 'smtp' = the framework's own socket transport (fill host/username/…).
// fromAddress MUST be set per project before mails can be sent (empty → send fails
// with a clear error, EmailService surfaces it as false + log).

return [
    'enabled'     => true,
    'transport'   => 'mail',
    'fromAddress' => '',
    'fromName'    => '',
    // transport 'smtp' only:
    'host'        => '',
    'port'        => 587,
    'encryption'  => 'tls',
    'username'    => '',
    'password'    => '',
    'timeout'     => 15,
    'heloHost'    => 'localhost',
];
