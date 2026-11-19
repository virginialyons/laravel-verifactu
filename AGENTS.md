# Laravel Verifactu

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `vlec/laravel-verifactu`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.
- `package-status` and `package-close`: the workflow's first and last steps (see Workflow).
- `laravel-best-practices`, `spatie-laravel-php`, `spatie-security`, `spatie-version-control`, `testing-best-practices`: copied from the service; `package-close` keeps them current.

## House Rules

- **This repository is public, and the package is for the company's own apps only.** Never name those apps in any file, test, fixture or commit: call them "the first app" and "the second app". Never describe the Verifactu service's internals; the package knows only its public API.
- English names everywhere. Spanish only for AEAT's own values (`E1`, `F2`, `R1`…).
- Money is whole cents (`int`), never a float. Tax is worked out with `BcMath\Number`.
- Call sites read like Laravel's: short, plain-English model methods (`$invoice->sendToVerifactu()`), the machinery hidden in the trait, the jobs and the client.
- Use what Laravel and the installed packages already provide; check the **installed** version's docs and source in `vendor/` before writing anything by hand. Never write framework class or method names from memory.
- Write `if` statements, not ternaries. Constants are PascalCase. No `env()` outside `config/`.
- The token decides the environment (`vf_test_` → Test, `vf_prod_` → Live). Never log, print or show the token or the webhook secret. Tests never send anything to a real service: `Http::preventStrayRequests()`.
- Work on `main`; no branches or PRs. Commit and push only when the owner asks. No AI or Claude mentions in commits.
- `CLAUDE.md` is a link to this file: edit `AGENTS.md`, never write to `CLAUDE.md`.

## Workflow

Run `/package-status` first in every session. The loop is `package-status` → `create-plan` → `implement-plan` → `/code-review` → `package-close`, recorded in `.plans/index.md` (the board), `.plans/<id>-progress.md` (trackers) and `.reviews/findings.md` (the ledger). The owner approves every plan before code is written.

The API this package wraps is documented by the Verifactu service's `verifactu-docs` MCP server (`.mcp.json`): `search_docs`, `get_doc_page`, `get_openapi_spec`, `get_invoice_example`, `list_error_codes`. Read it there; never guess a field, status or error code.

Laravel's docs for the installed versions: Boost's `search-docs`, when a `laravel-boost` MCP server is registered locally for this folder (it isn't committed). Otherwise check `vendor/` and the official docs.
