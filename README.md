# z77/kernel

Foundation package of the z77 PHP MVC framework — one Composer package, three namespaces.
Every z77 project and every z77 module depends on it.
Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not commit here.

| Namespace | Aspect | Directory |
|---|---|---|
| `Z77\Core` | boot — framework start / runtime | `core/` |
| `Z77\Shared` | platform — common base for all modules | `shared/` |
| `Z77\Persistence` | storage — reading and writing data | `persistence/` |

The three are functionally inseparable (login and save span all three), so they ship
as one package rather than three cyclic ones. See
[ADR-023](https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-023-kernel-package-core-shared-persistence.md).

## Requirements

- PHP ≥ 8.2 with `mbstring`, `fileinfo`, `zip`. No Composer runtime dependency
  ([ADR-001](https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-001-bootstrap-minimal-dependencies.md)).
- Suggested: `ext-apcu` (shared data cache across requests; without it a per-request cache),
  [`z77/kibot`](https://github.com/z77-ch/z77-framework/tree/main/packages/kibot) (website bot and backend
  KI-Assistent), [`z77/persistence-doctrine`](https://github.com/z77-ch/z77-framework/tree/main/packages/persistence-doctrine)
  (relational entities on MariaDB — required by the business modules that need it).
- Three libraries ship as frozen source snapshots under `vendored/` (QR encoder bacon-qr-code, its
  dependency dasprid/enum, the PDF writer FPDF), used only through the `Z77\Shared\Qr` / `Z77\Shared\Pdf`
  facades — see [`vendored/README.md`](vendored/README.md).

## What is inside

**`Z77\Core` — boot and request cycle**

- `Bootstrap`, `DI` — boot phases and the service container; `pullUpServices()` is the boot of a CLI entry.
- `Routing\` — convention routing `/{module}/{group}/{controller}/{action}`, aliases and language prefixes.
- `Http\` — `Request` (the only door to HTTP input), typed `Response` objects returned through the
  controller helpers (`html()`, `fetch()`, `json()`, `redirect()`, `file()`, …), `AccessGuard`, CSRF,
  page cache, ETag.
- `Controller\`, `Services\` — the abstract controllers, layout/view (`LayoutManager`), `Translator`,
  navigation, help (`HelpService`, ADR-048), auth and session services.
- `Installer\Install` — the Composer script that sets up and updates a project: config, data seeds,
  `public/` entry files, asset publication (`res/assets` → `public/assets/{package}`).
- `Kibot\` — the z77 adapter of `z77/kibot` (`KibotEndpoint`) and `HelpKnowledge`, which turns every
  `*.help.tpl.php` into the backend assistant's knowledge (ADR-055).

**`Z77\Shared` — platform building blocks for the modules**

Auth and roles, forms (`FormGuard`, public form handler), listing (`ListDefinition` and the list
partials), paging, upload component, tree (`TreeService`), money (`Money`, `AmountFormat`), PDF writer
and QR encoder facades (the Swiss QR-bill itself is built in module-debtor on top of them), mail, jobs (`z77-run`), backup/restore, import (staging, plan, apply), statistics,
GeoIP, alerts, API results, build stamp. The shared templates under `shared/res/view/templates/` hold
the partials every backend screen uses (list head and search, pager, row handle, confirm, upload,
help trigger, CSRF field) and the shared PDF layouts (address window, table, report).

**`Z77\Persistence` — storage**

`UnifiedEntityManager` and the File driver: JSON files under `data/`, atomic writes, exclusive locks
for read-modify-write, optimistic locking, validation and input cleaning. A second driver plugs in by
convention (`Z77\Persistence\{Driver}\Bootstrap`) — `z77/persistence-doctrine` is that driver.

**CLI** (`vendor/bin/`)

| Binary | Purpose |
|---|---|
| `z77-run` | the single cron entry: runs the due jobs, silent when idle (ADR-031) |
| `z77-backup {data\|db\|full}` | one backup with retention (ADR-028) |
| `z77-restore` | reads an SQL dump back into the database |

## Screens

The kernel has no backend screens of its own. The screens of its services (backups, jobs, statistics,
form log, translations, cache, users) live in
[`z77/module-backend`](https://github.com/z77-ch/z77-framework/tree/main/packages/module-backend).

## Getting started

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. It ships the full project `composer.json`, runs the installer
(`Z77\Core\Installer\Install::run` on `post-install-cmd` / `post-update-cmd`), and lays out the
override structure. `composer require z77/kernel` only adds the kernel to an **existing** z77 project.

A project never edits the kernel: configuration, controllers and templates are overridden under
`override/` (CE principle — see
[architecture.md](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/architecture.md)).

## Not built yet

A selection; the full, current lists are the `## pending` sections of the topic docs.

- Production error handler without paths/traces (security.md SEC-002); an enforced page CSP (today
  report-only); a backend editor for `systemConfig`.
- Module config override as a deviation (merge) instead of a whole-file replacement (bootstrap.md
  BOOT-CONFIG-001).
- Import of foreign sources (mapping + SQL-dump reader) and an upload form for the import (import.md).
- Missing-key adoption in the backend translation editor (translation.md TRANS-SEED-001).
- Publication of assets from a project's `override/…/res/assets` (installer.md INST-OVERRIDE-ASSETS-001).
- Cache monitor (cache.md FEAT-MON001/002); an SMS alert channel (alert.md ALERT-003).

## Documentation

Topic docs (single source of truth per area) in
[`docs/topics/`](https://github.com/z77-ch/z77-framework/tree/main/docs/topics) — for the kernel:
`bootstrap`, `routing`, `security`, `fetch`, `view-layer`, `forms`, `listing`, `messages`, `i18n`,
`translation`, `installer`, `persistence-architecture`, `persistence-file`, `entity-data-handling`,
`cache`, `tree`, `money`, `pdf`, `backup`, `import`, `stats`, `geoip`, `alert`, `kibot`.
Start at [`docs/README.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/README.md).

## License

MIT — Peter Ruepp, [z77.ch](https://z77.ch).
