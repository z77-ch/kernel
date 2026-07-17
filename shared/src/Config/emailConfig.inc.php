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
// 'forms' maps a form key (EmailService::sendForm) to its mail settings:
//   'contactForm' => [
//       'to'       => 'owner@example.ch',           // recipient(s), ','/';' separated
//       'subject'  => 'New contact form request',
//       'template' => ['partials/emails/contactForm', 'Z77\\Module\\Frontend'],
//       'cc'       => '',                           // optional
//   ],

return [
    'subjectPrefix' => '',
    'replyTo'       => null,   // default Reply-To; the sendForm() parameter wins
    'forms'         => [],
];
