---
title: Technical Stack
audience: Contributors and agents
covers: >
    Every technology choice with its status and reasoning, the supported PHP and
    Laravel matrix, and the rules for changing a stack decision.
read_before: Touching dependencies, version constraints, or CI configuration.
tags: [ stack, dependencies, versions, php, laravel, ci, decisions ]
---

# Technical Stack

The technology choices behind `lara-spec-first`, and the reasoning that produced them.

The project is in **early bootstrap** (Phase 1 of the [Roadmap](./ROADMAP.md)). `composer.json` now
declares the `Decided` rows below; the `Planned` ones are not installed yet. **The Status column is
binding:** a `Planned` or `Undecided` row is not a settled decision and must not be presented as one.

| Concern                  | Choice                             | Status      | Notes                                                                                                                                                                                                                                                                         |
|--------------------------|------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Language                 | PHP `^8.3`                         | **Decided** | Minimum 8.3. Do not use syntax newer than 8.3 in `src/` — it must still parse on the lowest supported runtime. 8.2 was ruled out: its security support ends 2026-12-31.                                                                                                       |
| Framework                | Laravel 12.x, 13.x                 | **Decided** | Constraint `^12.0 \| ^13.0`. Laravel 11 is excluded: it reached end of life on 2026-03-12. It can be added back later as a **minor** release if users ask — widening support is non-breaking, narrowing it is not.                                                            |
| OpenAPI parser           | `devizzent/cebe-php-openapi`       | **Decided** | Drop-in fork of `cebe/php-openapi` adding OpenAPI **3.1** support. Same `cebe\openapi\` namespace, so the two cannot coexist in one project.                                                                                                                                  |
| OpenAPI versions         | 3.0.x and 3.1.x                    | **Decided** | Both versions are *parsed*; a documented subset is *honoured*. Modern design tools export 3.1. Feature coverage, and the version differences behind it, live in [`OPENAPI-SUPPORT.md`](./OPENAPI-SUPPORT.md).                                                                 |
| OpenAPI version handling | Strategy per minor version         | **Decided** | One concrete strategy per OpenAPI minor version, so 3.0/3.1 differences stay in one place and a third version is a new class rather than an audit. Seam and open questions: [`OPENAPI-SUPPORT.md`](./OPENAPI-SUPPORT.md#handling-30-and-31-the-version-strategy).             |
| Remote `$ref` policy     | Domain allowlist, empty by default | **Decided** | A spec that can fetch a URL is a network client running with the app's credentials. Allowlisted hosts resolve; anything else is a clear exception. A fetched reference is then treated as a **dependency** — lock file, committed vendored copy, integrity check — never as a cache entry, so the runtime never touches the network. Rules: [`OPENAPI-SUPPORT.md`](./OPENAPI-SUPPORT.md#remote-references-and-the-domain-allowlist). Config keys, file names and command names not yet chosen. |
| Package test harness     | `orchestra/testbench`              | **Decided** | Boots Laravel inside the package test suite. Its major tracks Laravel minus two. Constraint `^10.11 \| ^11.0`: testbench 10.11 needs Laravel `^12.55`, testbench 11 needs Laravel `^13.1` and PHP `^8.3`.                                                                     |
| Development app          | `orchestra/workbench`              | **Decided** | A real Laravel application in `workbench/` with the package loaded, served through Compose. Ships with `testbench`, so it costs no extra dependency and replaces maintaining a separate Laravel project.                                                                      |
| Test runner              | Pest                               | **Decided** | Runs on PHPUnit underneath, so PHPUnit assertions and `testbench` integration still apply. Write new tests in Pest style (`it()`, `expect()`). Constraint `^4.7 \| ^5.0`: Pest 5 requires PHP `^8.4`, so PHP 8.3 resolves to Pest 4.                                          |
| Contract testing         | `Spectator`                        | Planned     | Validates responses against the spec in CI.                                                                                                                                                                                                                                   |
| Mocking                  | Faker                              | Planned     | Phase 2 — fallback responses for unimplemented endpoints.                                                                                                                                                                                                                     |
| Code style               | Laravel Pint                       | **Decided** | Constraint `^1.30`, `laravel` preset (`pint.json`). Run `composer format` to apply, `composer format:check` to report only.                                                                                                                                                   |
| Static analysis          | `larastan/larastan`                | **Decided** | PHPStan with Laravel-aware extensions. Constraint `^3.10`, **level 8** (`phpstan.neon.dist`). Started strict deliberately: raising the level on an existing codebase is far harder than starting there.                                                                       |
| Command runner           | `just`                             | **Decided** | Optional convenience only. Every recipe wraps a Composer script — it must never carry logic of its own, or the native path stops matching. The `justfile` opens with a security note: it executes shell commands.                                                             |
| Dev environment          | Docker + Compose                   | **Decided** | *Provided, not required.* `Dockerfile` defines the environment, `compose.yaml` defines how it is invoked (bind mount, host UID/GID, Composer cache, and the port mapping `serve` needs). Both only ever run Composer scripts — never their own logic.                         |
| CI                       | GitHub Actions                     | Planned     | Runs the suite **without** Docker across PHP 8.3 / 8.4 / 8.5 x Laravel 12 / 13 — six combinations, all valid, no `exclude` block needed.                                                                                                                                      |
| Distribution             | Packagist                          | Planned     | Package name **decided**: `gcob/lara-spec-first`, namespace `Gcob\LaraSpecFirst\`. Not yet published — publishing and the first tag are still ahead.                                                                                                                          |
| License                  | MIT                                | **Decided** |                                                                                                                                                                                                                                                                               |

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
