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

**`0.1.0` building (2026-11-19).** Plan approved by the owner on 2026-11-19, with its decomplex review folded in ([0.1.0-laravel-package-decomplex.md](../.reviews/0.1.0-laravel-package-decomplex.md)). T1 in progress. **Next:** `implement-plan` T2, from a session opened in this folder.

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
