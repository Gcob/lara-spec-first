---
title: Project Roadmap
audience: Users and contributors
covers: >
    The vision and the three delivery phases: foundations, developer experience
    and mocks, and the Code-First to Spec-First migration bridge.
read_before: Proposing or starting new work, to check which phase it belongs to.
tags: [ planning, migration, scope, openapi, testing ]
---

# Project Roadmap: lara-spec-first

This document outlines the vision, phases, and milestones for `lara-spec-first`.

## Phase 1: The Foundation (Current)

*Goal: Prove the core concept with a working proof-of-concept.*

- [x] Initialize package structure: `composer.json`, PSR-4 autoloading, the service provider skeleton
  with package discovery, and the development toolchain (Docker, Pest, Pint, Larastan, `just`).
- [ ] Integrate `devizzent/cebe-php-openapi` (OpenAPI 3.0.x + 3.1.x) to parse single or multi-file YAML specs,
  behind [one strategy per OpenAPI minor version](../guide/openapi-support.md#handling-30-and-31-the-version-strategy)
  so that nothing downstream ever knows which version was loaded.
- [ ] Produce the [contract artifact](../internals/contract-artifact.md): the normalized,
  resolved, version-neutral representation of what the package honors. It is the strategy's output and
  what `spec:doctor` checks against, so it is needed here — long before the breaking-change enforcement it will
  later serve as a baseline for.
- [ ] Build the core Service Provider that registers the generated routes. It **does not read the
  spec** — only the build commands do. Explicit over dynamic: see
  [the runtime never sees the spec](../guide/code-generation.md#the-runtime-never-sees-the-spec).
- [ ] Ship `spec:build`: resolve the spec into the contract artifact and generate the routes and
  the abstract controllers (Generated vs. Extended pattern). Safe to re-run, and it writes only files
  it owns. See [`code-generation.md`](../guide/code-generation.md).
- [ ] Ship `spec:make`: the only command that creates a file the developer will own. It scaffolds a
  named operation, or a whole `--tag`, or `--all` — never as a side effect of a build. `spec:build`
  itself never scaffolds; it prints the commands to run. See
  [scaffolding](../guide/code-generation.md#scaffolding-is-specmake-not-a-build-step).
- [ ] Answer unimplemented operations with `501`, from a package-provided handler that names the
  `spec:make` command to run. See
  [501](../guide/code-generation.md#an-unimplemented-operation-answers-501).
- [ ] **Remote reference vendoring.** `lara-spec-first.remote_references.allowed_hosts` exists and
  refuses to pretend: naming a host throws until this lands. What it needs is the fetch itself, the
  vendored copy committed beside the specification, and the resolution of the reference against that
  copy rather than the network. See [remote references](../guide/remote-references.md).
- [ ] A conformance suite over the reading engine, organized by equivalence class.
  **Not routine coverage — a deliberate answer to a risk already observed.** Two defects with no
  symptom have been found in the OpenAPI parser within days of first use, on a surface no wider than
  paths and references: a pure `$ref` cycle exhausts memory instead of raising, and
  `components.pathItems` loses an endpoint without reporting anything. Both are recorded in
  [parser caveats](../guide/openapi-support.md#parser-caveats), and neither would have been prevented by
  putting an interface in front of the parser — an adapter guards against *swapping* a dependency,
  where what has actually gone wrong is the dependency *being wrong*. Behaviour is therefore what gets
  pinned.

  The suite partitions the input space rather than accumulating examples, so that coverage can be
  argued instead of hoped for: by version, with the same contract written as 3.0 and as 3.1 and
  required to normalize identically — the version strategy's entire promise, and the
  one class that already exists (`tests/Conformance/`); by reference form (local, cross-file, blocked, cyclic, recursive
  schema, and each
  form a Path Item reference can take); by the positions where OpenAPI mixes data with specification;
  by document shape (empty, no paths, webhooks-only, components-only); and by failure class, keeping
  document faults, package limits and parser defects distinct in the assertions the way
  [the doctor](../guide/doctor.md#two-kinds-of-finding-never-mixed) keeps them distinct in its report.

  Every defect found in the parser earns a permanent case, so the list of what we know about it can
  only grow. And the suite ends up being what an adapter was wanted for: **the acceptance criteria a
  replacement parser would have to meet.** An interface would only prove a substitute compiles; this
  proves one behaves.

- [ ] Ship `spec:doctor` — `nginx -t` for your contract: what the package will honor, what it
  will not, and the routing table that results. It belongs in this phase, not with the other Artisan
  commands: it is what makes "the spec is the source of truth" verifiable rather than asserted. See
  [the doctor](../guide/doctor.md).

## Phase 2: Developer Experience & Mocks

*Goal: Make adoption frictionless and fast.*

- [ ] Implement automated Faker-based mocking for endpoints lacking concrete controller implementations.
- [ ] A mock server driven by the spec: serve the whole contract with conforming responses, with no
  application behind it. Distinct from the in-app fallback above — that one fills the gaps in a real
  application, this one needs no application at all.
- [ ] Extend the build to response DTOs and request validation, on top of the Phase 1 routes and
  controllers. Generation and validation are not new commands: they are the Phase 1 build and the
  Phase 1 doctor, doing more. See [`code-generation.md`](../guide/code-generation.md#response-dtos).
- [ ] Support OpenAPI versioning directories (`v1/`, `v2/`).
- [ ] Ship `spec:watch`: the design loop, rebuilding on change and allowed to fetch references that
  `spec:build` deliberately refuses to. A separate command because a running process states intent
  every time and dies with the terminal, where a config key would quietly follow you into CI. See
  [watching](../guide/code-generation.md#watching-specwatch).
- [ ] Spec-driven test data, so that testing an endpoint does not start by writing a factory. The
  schema already states the shape, the constraints and often the examples — the package should be able
  to produce a conforming payload from it. **Where this stops matters and must be said plainly:** a
  schema describes shapes, not domain truth. Referential integrity, business invariants and database
  constraints are not in it. Spec-driven data can replace a factory for HTTP-level and mock-server
  tests; it cannot replace one for tests that persist to a database.

### The thesis this phase is proving

Taken together, the generated pipeline is the point of the whole package: for an ordinary CRUD
endpoint, **the route, the form request, the controller and the DTO are all derived from the
contract**, and the only thing a developer writes is the model and the business logic that model
carries. Not less typing for its own sake — less surface where the code and the contract can quietly
disagree.

## Breaking-change enforcement

*Goal: a stable operation cannot break without someone deciding to break it.*

Deliberately not slotted into a phase yet: a rule that fails somebody's build has to be right before
it ships, and it depends on groundwork the earlier phases have not laid. See
[lifecycle](../guide/lifecycle.md#unstable-by-default-and-what-stable-costs-us).

- [ ] Diff the Phase 1 [contract artifact](../internals/contract-artifact.md) against its
  committed predecessor. Comparisons are made between artifacts, never between specification documents.
- [ ] The breaking-change table, direction-aware for requests and responses, versioned as public API.
- [ ] Fail the build on a breaking change to a `stable` operation, naming the `info.version` bump that
  would make it legitimate.

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

- [ ] Tooling to bootstrap a spec from an existing Code-First app, and to verify it against real behavior before
  cutover. *(Design in progress — more to come.)*
- [ ] First-class integration with `Spectator` for automated contract testing in CI/CD pipelines.
- [ ] Comprehensive documentation and real-world migration examples.
