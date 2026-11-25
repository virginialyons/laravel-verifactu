---
name: package-compatibility
description: "Use this skill when reviewing Laravel package compatibility across composer constraints, PHP versions, Laravel versions, Testbench versions, dependency stability lanes, or matrix-sensitive code and workflow changes."
license: MIT
metadata:
  author: laravel
---

# Package Compatibility

## Primary Goal

Keep package code, dependencies, and workflows compatible with the supported matrix: PHP ^8.4 (8.4 and 8.5) and the latest Laravel 13 with Testbench 11, on Ubuntu (0.1.0 plan Decisions 2 and 3; 0.2.0 plan Decision 2). The package is for the company's own apps, which update with it: when a Laravel feature needs a newer release, raise the floor in `composer.json` and use it.

## Workflow

1. Read `composer.json` first to determine PHP, Laravel, and Testbench constraints.
2. Check changed code against the lowest Laravel release `composer.json` allows and PHP 8.4 syntax. If a framework feature needs a newer release, raise the floor in the same change.
3. Review `.github/workflows/tests.yml` (run by hand, `workflow_dispatch` only) for its prefer-lowest and prefer-stable lanes.
4. When changing dependencies, confirm constraints still allow the intended Laravel and Testbench versions.
5. Validate with the smallest local command available, then `composer test`, the gate; start the workflow by hand when a change is matrix-sensitive.

## References

- `composer.json`
- `.github/workflows/tests.yml`
- `phpstan.neon.dist`
- `tests/`
- `workbench/`

## Examples

- Review a new Laravel API call by checking whether it exists in the floor `composer.json` names; if not, raise the floor.
- Review a dependency bump by checking Composer constraints, Testbench constraints, and prefer-lowest behavior.

## Anti-Patterns

- Assuming the latest local dependency version represents the whole support matrix.
- Adding PHP syntax or Laravel APIs that exceed `composer.json` constraints.
- Removing dependency stability lanes because they are slower than a single happy path.
