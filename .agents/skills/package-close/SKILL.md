---
name: package-close
description: Closes a finished milestone of this package by verifying its gate, archiving the plan and updating the board, then hands the commit, push and release decision to the owner. Use this skill when a milestone's implementation is complete and reviewed, or when asked to close, finish, archive or check whether a milestone is ready to release. Do not use to write a plan (use create-plan), to build one (use implement-plan), to run a review (use /code-review), to report progress (use package-status), or to tag a release (use package-release).
---

# Package close

Turns a finished milestone into history: simplify, verify the gate, archive the plan, update the board, ask about the push.

## Rules

- **Never record `Done` for a milestone that has not passed every gate check.** `package-status` trusts the board, so a false `Done` misleads every later session.
- **A failed check stops the run.** Report it with the evidence and stop. Fix versus descope is the owner's call.
- **Committing, pushing and tagging are the owner's decision.** Never do them unasked. Work stays on `main`; no branches or PRs.
- **This repository is public.** Never write the company's app names into any file; call them "the first app" and "the second app".

## Simplify first

Run `laravel:laravel-simplifier` over the milestone's PHP diff **before** the gate; simplification changes code, so a gate run before it is stale. Brief it to ask, for every hand-written query, retry, lock, guard, middleware, job hook or fake: "Does Laravel or an installed package already do this in the **installed** version?" Answer it with the docs and the source in `vendor/`, and cite the evidence. Flag deprecated APIs, methods that match a framework hook name in another letter case, and guards that cover only some of the paths that write.

Disposition every recommendation: apply it and re-run the affected checks, record it in `.reviews/findings.md` with a severity, or reject it with a reason. A simplification that changes behavior is a defect, not a cleanup.

## Gate

Run in order; stop at the first failure.

| #   | Check                    | Fails if                                                                                                                                                       |
| --- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Tracker complete         | Any row `Pending`, `In progress` or `Blocked`, or `Descoped` without the owner's recorded approval                                                             |
| 2   | No blocking findings     | Any P0 or P1 in `.reviews/findings.md` is `open` or `fixed`                                                                                                    |
| 3   | `composer test`          | Non-zero exit, or `composer test:mutate` (90% minimum) fails                                                                                                    |
| 4   | "Done when" demonstrated | You can't name the artifact that showed each criterion: a real run against the service in Test, a recorded answer or webhook, a command's output. A green suite alone is not a demonstration |
| 5   | Dependencies current     | `composer outdated --direct` lists a package with no upstream blocker recorded on the board                                                                    |
| 6   | Skills current           | A skill copied from the service (`laravel-best-practices`, `spatie-*`, `testing-best-practices`) differs from the service's copy. Refresh it, then re-check     |
| 7   | No app names             | `git grep -i -E` for the company's app names (typed in the terminal, never saved in a file) finds a match                                                       |
| 8   | Tree clean               | `git status --porcelain` shows uncommitted changes                                                                                                             |

A lesson about AEAT or the service learned in this milestone goes to the service's `verifactu-development` skill, in the service repository, not here.

## After the gate

1. Move the plan and its tracker to `.plans/done/`, keeping the tracker's evidence.
2. Update `.plans/index.md`: the milestone row to `Done` with the archived path and date, a History row, a rewritten Current position, and any Open decision this milestone resolved.
3. Report the simplify outcome, each gate line with its evidence, the archived path, the board changes and carried findings **by ID**. End by asking whether to commit and push, and whether to tag the release with `package-release`.
