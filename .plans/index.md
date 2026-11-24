# Laravel Verifactu delivery board

The single place that answers **"where am I and what do I run next?"** for this package.

- **Findings ledger:** `.reviews/findings.md`
- **History:** `.plans/done/`
- **The API it wraps:** the Verifactu service's public docs and OpenAPI file, through the `verifactu-docs` MCP server (`.mcp.json`)

Run `/package-status` at the start of any session. It reads this file and tells you the next command.

This repository is public: no file ever names the company's apps. They are "the first app" and "the second app".

---

## The loop

| #   | Step   | Command                                                    | Gate                                                                                         |
| --- | ------ | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| 1   | Orient | `/package-status`                                          | —                                                                                            |
| 2   | Plan   | `create-plan` → `.plans/<id>-<slug>.md`, then `decomplex`  | **The owner reviews the plan before any code is written**                                    |
| 3   | Build  | `implement-plan` (tracker `.plans/<id>-progress.md`)       | Each task: `composer test` green                                                             |
| 4   | Review | `/code-review`, `/security-review`                         | Unfixed findings land in `.reviews/findings.md`                                              |
| 5   | Close  | `/package-close <id>`                                      | Simplifier run · no open/fixed P0/P1 · `composer test` and mutation green · Done when shown   |

**Everything happens on `main`:** no branches, no PRs. Commits are pushed when the owner asks. GitHub's `tests.yml` runs only when started by hand; local `composer test` is the gate.

### Skills by step

Load every matching skill **before** writing. Each plan task names its skills and agents.

| Step   | Skills and agents                                                                                                                         |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Plan   | `create-plan`, `decomplex`, `kiss-dry-yagni`, `grill-me`; docs through the `verifactu-docs` MCP and the `docs-explorer` agent              |
| Build  | `laravel-best-practices`, `spatie-laravel-php`, `testing-best-practices`, `package-scaffold`, `package-testing`; `spatie-security` for webhooks, tokens and secrets; `spatie-version-control` for commits |
| Review | `/code-review`, `/security-review`                                                                                                        |
| Close  | `/package-close <id>`, agent `laravel:laravel-simplifier`; `package-release` to tag                                                       |

---

## Milestones

| ID      | Milestone                                        | Status     | Plan                                                     | Closed |
| ------- | ------------------------------------------------ | ---------- | -------------------------------------------------------- | ------ |
| `0.1.0` | First release: issue, batch, fix, cancel, webhooks | `Done`     | [done/0.1.0-laravel-package.md](done/0.1.0-laravel-package.md) | 2026-11-20 |
| `1.0.0` | Stable: the second app in production             | `Pending`  | —                                                        | —      |

Status: `Pending` · `Planning` · `Building` · `Review` · `Done` · `Blocked`

---

## Current position

**`0.1.0` done (2026-11-20).** Released as `v0.1.0` on GitHub and Packagist; the plan and its tracker (every task's evidence) are in [done/0.1.0-laravel-package.md](done/0.1.0-laravel-package.md) and [done/0.1.0-progress.md](done/0.1.0-progress.md). Closed with every gate passed: T1–T14 verified; no P0 or P1; `composer test` green (410 tests) and `composer test:mutate` 94.48%; Done when shown (1: T11's run against the service in Test; 2: the suite and mutation run; 3: `composer require vlec/laravel-verifactu:^0.1` in a new Laravel 13.43 app; 4: the service's Laravel guide and endpoint tabs, T14); dependencies current (`laravel/pao` 1.1.6); copied skills match the service's; no app names; tree clean. The simplifier ran in T12 on the same PHP (no PHP change since). Carried findings: F013 (P2, a worker timeout fails a job for good; left by the owner) and F016 (P3, refusals the docs don't define yet) open; F008, F009, F012 (P3) fixed, not yet verified. The service's T14 docs changes are committed in its repository. **`0.1.1` released (2026-11-24)** to match the service's yearly invoice numbers (see History). **Next:** `create-plan` for `1.0.0` when the owner starts it.

---

## Open decisions

A decision the owner has not made is a stop, not an assumption.

None.

---

## History

| Date       | Event                                                                                                     |
| ---------- | --------------------------------------------------------------------------------------------------------- |
| 2026-11-19 | `0.1.0` plan approved (owner); decomplex review folded in (DEX-001–003 applied, DEX-004 kept).            |
| 2026-11-19 | Package scaffolded from Laravel's official skeleton; workflow, board and skills set up (T1).              |
| 2026-11-20 | `0.1.0` released (`v0.1.0`, GitHub and Packagist) and closed; plan archived to `.plans/done/`.            |
| 2026-11-24 | `0.1.1` released (`v0.1.1`, GitHub and Packagist): `Verifactu::fake()` numbers invoices `SERIES-YYYY-NNNNN` by issue year, as the service now does. `composer test` green (418 tests), `composer test:mutate` 94.59%; Laravel simplifier and code simplifier run. |
