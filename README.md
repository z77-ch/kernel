# z77/kernel

Foundation package for the Z77 framework — one Composer package, three namespaces.
Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not commit here.

| Namespace | Aspect | Directory |
|---|---|---|
| `Z77\Core` | boot — framework start / runtime | `core/` |
| `Z77\Shared` | platform — common base for all modules | `shared/` |
| `Z77\Persistence` | storage — reading and writing data | `persistence/` |

The three are functionally inseparable (login and save span all three), so they ship
as one package rather than three cyclic ones. See
[ADR-023](https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-023-kernel-package-core-shared-persistence.md).

## Getting started

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. It ships the full project `composer.json`, runs the installer, and lays
out the override structure. `composer require z77/kernel` only adds the kernel to an
**existing** z77 project.
