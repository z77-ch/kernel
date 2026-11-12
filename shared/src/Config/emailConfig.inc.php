<?php
//
// emailConfig — app-level mail settings (EmailService), override-first.
//
// Framework default: safe/empty. A project overrides the WHOLE file at
//   override/z77/shared/src/Config/emailConfig.inc.php
// (first FileFinder sourcePaths match wins — no merging).
//
// Transport, enabled flag and sender identity (fromAddress/fromName) live in
// the project's config/client/mail.inc.php — NOT here (single config source, Rule 2).
//
// 'forms' maps a form key (EmailService::sendForm) to its mail settings.
// This config is the SEED/FALLBACK: a backend-edited EmailFormSetting record
// (Service → E-Mail) overrides to/cc/subject/routes completely per form key;
// the template always comes from here (developer artifact). See
// docs/03-development/email-settings-v2-bauplan.md.
//
// Seed-address convention (mail.md): 'to' here is the DEVELOPER's deliverable
// test inbox (e.g. webmaster@{domain}) — NOT the client's production recipient.
// The developer ensures it exists (mailbox/forwarding) on the staging host; the
// production recipient is set in the backend override at handover. Before
// go-live no form may still run on the config 'to' (origin «Config» in the
// backend list).
//   'contactForm' => [
//       'to'       => 'webmaster@example.ch',       // dev test inbox — client recipient goes in the backend
//       'subject'  => 'New contact form request',
//       'template' => ['emails/publicForm', 'Z77\\Shared'],
//       'cc'       => '',                           // optional
//       'routes'   => [                             // optional: routeKey → recipient override
//           'verwaltung' => ['to' => ['a@x.ch', 'b@x.ch'], 'subject' => 'Verwaltungsanfrage'],
//       ],
//   ],
//
// `template` may stay on the generic body ['emails/publicForm', 'Z77\\Shared'],
// which renders whatever the FormDefinition declares — a project only needs its
// own body template when the mail must look different.

return [
    'subjectPrefix' => '',
    'replyTo'       => null,   // default Reply-To; the sendForm() parameter wins
    'forms' => [
        // The reference contact form (module-frontend IndexController::contactAction).
        // 'to' is a placeholder — set a deliverable developer address per project
        // (seed-address convention, mail.md), the client recipient goes in the backend.
        'contactForm' => [
            'to'       => '',
            'subject'  => 'New contact form request',
            'template' => ['emails/publicForm', 'Z77\\Shared'],
        ],
        // The monthly statistics mail (`stats-report-mail`, docs/topics/stats.md):
        // the link to last month's report. A project that switches the job on
        // copies this entry into its override and sets 'to'.
        'statsReport' => [
            'to'       => '',
            'subject'  => 'Besuchsstatistik',
            'template' => ['emails/statsReport', 'Z77\\Shared'],
        ],
        // Who is told when the backend refuses every 2FA code because its counters cannot be
        // written (login.md «code refused»). 'recipientsOnly': AlertService writes this mail itself,
        // so it has no template, and the backend offers the recipients alone ('note' says what the
        // entry is for). No recipient: nobody is told, the log line stays. A project with its own
        // emailConfig copies this entry, or the entry is not listed and nobody is told.
        'backendCodeLock' => [
            'to'             => '',
            'recipientsOnly' => true,
            'note'           => 'Alarm, wenn das Backend wegen eines technischen Problems keine 2FA-Codes annimmt',
        ],
        // Who is told when an address reaches the daily limit of sign-in codes for the z77 App
        // (module-api ApiAppLogin, ADR-058): a sign of someone flooding or guessing. Same shape as
        // backendCodeLock: AlertService writes the mail, no recipient = nobody is told.
        'apiLoginCap' => [
            'to'             => '',
            'recipientsOnly' => true,
            'note'           => 'Alarm, wenn für eine Adresse das Tageslimit an Anmeldecodes der z77 App erreicht ist',
        ],
    ],
];
