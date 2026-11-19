---
name: package-status
description: Reports where the package's delivery stands and the single command to run next, without changing anything. Use this skill when starting or resuming a session on this package, after a context clear, or when asked where things stand, what is left, what to do next, or whether a milestone is blocked. Do not use to write a plan (use create-plan), to build one (use implement-plan), to close a milestone (use package-close), or to review code (use /code-review).
---

# Package status

Answers "where am I and what do I run next?" from the board, the active plan's tracker, the findings ledger and git.

## Rules

- **Read-only.** Never modify plans, source, `.plans/index.md` or `.reviews/findings.md`. Report staleness; don't repair it.
- Trust the repo over the board. If the board says `Done` and the code is absent, report the contradiction.
- An unresolved row in the board's Open decisions that blocks the next step is a stop, not an assumption.
- Every reported field comes from a file read or command run in this session.

## Sources

1. `.plans/index.md`: milestone table, Current position, Open decisions.
2. The active plan and its tracker (`.plans/<id>-progress.md`). Count rows by status; find the first row not `Verified` or approved `Descoped`.
3. `.reviews/findings.md`: counts by severity and status; list every P0 and P1 that is `open` or `fixed`.
4. Git: branch, uncommitted changes, last commit.

## Report

```
Laravel Verifactu — <active milestone, or "not started">

Position   <board status> · <n>/<m> rows Verified
Plan       <path, or "none">
Blockers   <P0/P1 IDs, or "none">
Findings   P0:<n> P1:<n> P2:<n> P3:<n> open
Git        <branch> · <n> uncommitted · <last commit subject>

Next       <the exact command to run>
```

Then one line per Open decision that blocks the next step. Nothing else.

## Choosing Next

| State                                      | Next                                                  |
| ------------------------------------------ | ----------------------------------------------------- |
| No active milestone                        | `create-plan` for the first `Pending` milestone       |
| Plan exists, not yet approved by the owner | Name the plan path and stop; that gate is the owner's |
| Rows unstarted or in progress              | `implement-plan`                                      |
| All rows `Verified`, no review recorded    | `/code-review`                                        |
| Any `open` or `fixed` P0/P1                | Fix those first, by ID                                |
| Review clear                               | `package-close <id>`                                  |
| An Open decision blocks the step           | Say which, and stop                                   |

`fixed` is not clear: only `verified`, or `accepted` by the owner, releases a P0/P1. Next is one runnable command or one decision for the owner, never a menu.
