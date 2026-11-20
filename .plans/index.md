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
| `0.1.0` | First release: issue, batch, fix, cancel, webhooks | `Building` | [0.1.0-laravel-package.md](0.1.0-laravel-package.md)     | —      |
| `1.0.0` | Stable: the second app in production             | `Pending`  | —                                                        | —      |

Status: `Pending` · `Planning` · `Building` · `Review` · `Done` · `Blocked`

---

## Current position

**`0.1.0` building (2026-11-19).** Plan approved by the owner on 2026-11-19, with its decomplex review folded in ([0.1.0-laravel-package-decomplex.md](../.reviews/0.1.0-laravel-package-decomplex.md)). T1–T9 verified (see the tracker). On 2026-11-19 the owner asked for an independent review of every task and a sweep to Laravel's own helpers: nine reviewers, two more rounds, all fixes in, `composer test` green (331 tests). Committed on 2026-11-20 at the owner's request: T9, then the sweep (not pushed). Ledger: F001–F011, all P2/P3. Settled with the owner on 2026-11-20: F008 fixed (`laravel/framework ^13.0`), F004 accepted (E3 refused locally), F009 fixed (a direct call waits out at most 5 s of `Retry-After`); the rest are open. T10 verified on 2026-11-20 (README, Boost guideline and skill; F001–F003, F005, F006, F010, F011 verified by its reviewer; F012 opened, then fixed the same day when the owner made the service the source of truth: the package's local refusals are gone, house rule in `AGENTS.md`), not committed. T11 verified on 2026-11-20 with the owner present: Done when 1 holds against the local service in Test (a mixed order, a batch of three, a fix, a cancellation and a missed webhook caught up by `verifactu:refresh-pending`, all `accepted` or `cancelled` by AEAT), and F007 verified (the service and AEAT accept the -0.02 correction). The tests no longer read the workbench's `.env` or database. Committed and pushed. T12 verified on 2026-11-20: three simplifier reviewers (no P0 or P1; the fixes are in the tracker), `/code-review` (one low, fixed) and `/security-review` (no findings); `composer test:mutate` added (the tests declare no `covers()`), 78.90% → 94.48%; `composer test` green, 410 tests. Ledger: F013 (P2, a worker timeout fails a job for good) waits on the owner; F014, F015 wontfix; F016 (P3) waits on the service's docs. Not committed. **Next:** `implement-plan` T13 (tag `v0.1.0`, the owner submits it to Packagist).

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
