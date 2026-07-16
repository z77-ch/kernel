# CLAUDE.md — z77 Project

This is a project built on the **z77 PHP MVC framework**. This file was seeded by the
framework installer (seed-once — it is yours to edit; the installer never overwrites it).

## Read this first

**The framework documentation lives at [`vendor/z77/docs/`](vendor/z77/docs/README.md)** —
AI-optimized: handbook, ADRs, and one topic doc per work area.

- Not installed? Run `composer require --dev z77/docs` — it is version-matched to the
  framework packages.
- Entry point: [`vendor/z77/docs/README.md`](vendor/z77/docs/README.md), including a
  **topic trigger map** (which doc to read for which keyword).
- Before writing or analyzing code in an area, read `vendor/z77/docs/topics/{topic}.md` —
  the single source of truth for that area (rules, known issues, pendenzen).
- Building the first page? Follow
  [`vendor/z77/docs/01-handbook/create-page.md`](vendor/z77/docs/01-handbook/create-page.md).

## Project layout (z77 CE principle)

- **Your code lives under `override/z77/…`** — controllers, templates, and config
  overrides. The directory tree mirrors the framework namespaces.
- **The framework lives in `vendor/z77/*` and is never edited** — override, don't fork.
  Framework updates arrive via `composer update` without collisions.
- Runtime content: `data/` — one JSON file per record, no database.
- Public web root: `public/` — developer-owned after the first install; the installer
  never overwrites it.

## Key rules (short form — full reasoning in vendor/z77/docs)

1. Never edit `vendor/` — override under `override/z77/…`.
2. Every controller action returns a typed `Response` via a helper (`$this->html()`,
   `->json()`, `->redirect()`, …) — never a directly instantiated response.
3. HTTP input only through `Request` (`DI::getRequest()`) — never `$_GET` / `$_POST` /
   `$_SERVER` in a controller.
4. Every config value has exactly one, semantically named home — no copies.
5. As little JavaScript as possible — CSS first, then server-generated CSS, then JS.

## Deployment note

`CLAUDE.md` and the docs are development context — do not deploy them to the production
host (`composer install --no-dev` already excludes `z77/docs`).
