---
title: Technical Stack
audience: Contributors and agents
covers: >
  Every technology choice with its status and reasoning, the supported PHP and
  Laravel matrix, and the rules for changing a stack decision.
read_before: Touching dependencies, version constraints, or CI configuration.
tags: [stack, dependencies, versions, php, laravel, ci, decisions]
---

# Technical Stack

The technology choices behind `lara-spec-first`, and the reasoning that produced them.

The project is in **early bootstrap** (Phase 1 of the [Roadmap](./ROADMAP.md)). Most of what follows is
chosen but not yet installed — there is no `composer.json` yet. **The Status column is binding:** a
`Planned` or `Undecided` row is not a settled decision and must not be presented as one.

| Concern              | Choice                       | Status      | Notes                                                                                                                                                                                                              |
|----------------------|------------------------------|-------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Language             | PHP `^8.3`                   | **Decided** | Minimum 8.3. Do not use syntax newer than 8.3 in `src/` — it must still parse on the lowest supported runtime. 8.2 was ruled out: its security support ends 2026-12-31.                                            |
| Framework            | Laravel 12.x, 13.x           | **Decided** | Constraint `^12.0 \| ^13.0`. Laravel 11 is excluded: it reached end of life on 2026-03-12. It can be added back later as a **minor** release if users ask — widening support is non-breaking, narrowing it is not. |
| OpenAPI parser       | `devizzent/cebe-php-openapi` | **Decided** | Drop-in fork of `cebe/php-openapi` adding OpenAPI **3.1** support. Same `cebe\openapi\` namespace, so the two cannot coexist in one project.                                                                       |
| OpenAPI versions     | 3.0.x and 3.1.x              | **Decided** | Modern design tools export 3.1.                                                                                                                                                                                    |
| Package test harness | `orchestra/testbench`        | Planned     | Boots Laravel inside the package test suite. Its major tracks Laravel minus two: `^10` for L12, `^11` for L13. Constraint `^10.0 \| ^11.0`.                                                                        |
| Test runner          | Pest                         | **Decided** | Runs on PHPUnit underneath, so PHPUnit assertions and `testbench` integration still apply. Write new tests in Pest style (`it()`, `expect()`).                                                                     |
| Contract testing     | `Spectator`                  | Planned     | Validates responses against the spec in CI.                                                                                                                                                                        |
| Mocking              | Faker                        | Planned     | Phase 2 — fallback responses for unimplemented endpoints.                                                                                                                                                          |
| Code style           | Laravel Pint (PSR-12)        | Planned     |                                                                                                                                                                                                                    |
| Static analysis      | PHPStan                      | Planned     | Level not fixed.                                                                                                                                                                                                   |
| Dev environment      | Docker                       | **Decided** | *Provided, not required.* A thin wrapper that invokes the same Composer scripts — it must never carry its own logic.                                                                                               |
| CI                   | GitHub Actions               | Planned     | Runs the suite **without** Docker across PHP 8.3 / 8.4 / 8.5 x Laravel 12 / 13 — six combinations, all valid, no `exclude` block needed.                                                                           |
| Distribution         | Packagist                    | Planned     | Vendor/package name not fixed.                                                                                                                                                                                     |
| License              | MIT                          | **Decided** |                                                                                                                                                                                                                    |

## Supported versions at a glance

|             | Laravel 12 | Laravel 13 |
|-------------|------------|------------|
| **PHP 8.3** | supported  | supported  |
| **PHP 8.4** | supported  | supported  |
| **PHP 8.5** | supported  | supported  |

Every combination is valid, which is the point of the PHP 8.3 floor: the CI matrix needs no `exclude`
block. Laravel 13 requires PHP 8.3, so a lower floor would have introduced an impossible cell.

## Why these versions

Support windows, as published by Laravel and PHP:

| Release    | Security support ends     |
|------------|---------------------------|
| Laravel 11 | 2026-03-12 — already past |
| Laravel 12 | 2027-02-24                |
| Laravel 13 | 2028-03-17                |
| PHP 8.2    | 2026-12-31                |
| PHP 8.3    | 2027-12-31                |
| PHP 8.4    | 2028-12-31                |
| PHP 8.5    | 2029-12-31                |

Two consequences shaped the floor:

* **PHP 8.2 would have expired before this package reached 1.0.** Shipping on a floor that dies within
  months means raising it soon after — and raising a minimum is a breaking change requiring a major
  version bump. Starting at 8.3 avoids spending a major on housekeeping.
* **Laravel 11 is already out of security support.** Supporting three majors instead of two costs matrix
  cells, compatibility code, and the use of any API introduced after L11 — paid for a user base that
  does not exist yet. Adding L11 back later is a *minor* release; dropping it later would be a *major*
  one. When in doubt, start narrow and widen on request.

## Changing anything here

A stack decision is a durable commitment, not an implementation detail:

* **Record the reasoning, not just the value.** Every row above should let a reader six months from now
  understand *why* without redoing the research.
* **Move a row to `Decided` in the same change that installs it.** The table drifts fastest when a
  dependency lands and the status is updated "later".
* **Raising a minimum version is breaking.** PHP or Laravel floors, dropped majors, and removed
  extension points all require a major release. Widening support does not.
