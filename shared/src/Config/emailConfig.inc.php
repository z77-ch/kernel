<?php
//
// emailConfig — app-level mail settings (EmailService), override-first.
//
// Framework default: safe/empty. A project overrides the WHOLE file at
//   override/z77/shared/src/Config/emailConfig.inc.php
// (first FileFinder sourcePaths match wins — no merging).
//
// Transport, enabled flag and sender identity (fromAddress/fromName) live in
// the project's config/mail.inc.php — NOT here (single config source, Rule 2).
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
//       'template' => ['partials/emails/contactForm', 'Z77\\Module\\Frontend'],
//       'cc'       => '',                           // optional
//       'routes'   => [                             // optional: routeKey → recipient override
//           'verwaltung' => ['to' => ['a@x.ch', 'b@x.ch'], 'subject' => 'Verwaltungsanfrage'],
//       ],
//   ],

return [
    'subjectPrefix' => '',
    'replyTo'       => null,   // default Reply-To; the sendForm() parameter wins
    'forms'         => [],
];
