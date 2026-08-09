---
title: Project Roadmap
audience: Users and contributors
covers: >
  The vision and the three delivery phases: foundations, developer experience
  and mocks, and the Code-First to Spec-First migration bridge.
read_before: Proposing or starting new work, to check which phase it belongs to.
tags: [planning, migration, scope, openapi, testing]
---

# Project Roadmap: lara-spec-first

This document outlines the vision, phases, and milestones for `lara-spec-first`.

## Phase 1: The Foundation (Current)
*Goal: Prove the core concept with a working proof-of-concept.*
- [x] Initialize package structure: `composer.json`, PSR-4 autoloading, the service provider skeleton
  with package discovery, and the development toolchain (Docker, Pest, Pint, Larastan, `just`).
- [ ] Integrate `devizzent/cebe-php-openapi` (OpenAPI 3.0.x + 3.1.x) to parse single or multi-file YAML specs.
- [ ] Build the core Service Provider to dynamically register routes from the spec.
- [ ] Implement the base abstract controller generation (Generated vs. Extended pattern).
- [ ] Ship the diagnostic command — `nginx -t` for your contract: what the package will honor, what it
  will not, and the routing table that results. It belongs in this phase, not with the other Artisan
  commands: it is what makes "the spec is the source of truth" verifiable rather than asserted. See
  [the doctor](./OPENAPI-SUPPORT.md#where-the-diagnostics-go-the-doctor).

## Phase 2: Developer Experience & Mocks
*Goal: Make adoption frictionless and fast.*
- [ ] Implement automated Faker-based mocking for endpoints lacking concrete controller implementations.
- [ ] Add the remaining Artisan commands (`php artisan spec:generate`). Spec validation is **not** a
  separate command: it is the first section of the Phase 1 diagnostic command.
- [ ] Support OpenAPI versioning directories (`v1/`, `v2/`).

## Phase 3: Legacy Bridge & Ecosystem
*Goal: Turn an existing Code-First Laravel app into a Spec-First one — quickly, simply, and above all reliably.*

The hard part of adopting Spec-First is not the new code, it is the app you already have. An established API has
its contract scattered across controllers, form requests, and resources — and nobody wants to retype it into YAML
by hand.

The plan is to use existing Code-First tooling (`Scramble`, `L5-Swagger`) **once**, as an on-ramp: extract a spec
from the code you already run, then flip the direction of truth so the spec leads from that point on. It is a
one-way door, not a permanent round-trip.

Three properties matter, in this order:

* **Reliable** — you should be able to trust that the extracted spec actually describes what your API does today,
  *before* you hand it the keys. A migration you cannot verify is not a migration.
* **Simple** — adoptable route by route, never a big-bang rewrite.
* **Fast** — the boring parts should be mechanical.

- [ ] Tooling to bootstrap a spec from an existing Code-First app, and to verify it against real behaviour before
  cutover. *(Design in progress — more to come.)*
- [ ] First-class integration with `Spectator` for automated contract testing in CI/CD pipelines.
- [ ] Comprehensive documentation and real-world migration examples.
