---
title: Project Roadmap
audience: Users and contributors
covers: >
    The vision and how the work is sequenced: what the package actually does today, the three delivery phases, and the
    two bodies of work deliberately left outside a phase (breaking-change enforcement, and what has to be settled before
    the first tag).
read_before: >
    Proposing or starting new work, to check which phase it belongs to and what it depends on.
tags: [planning, migration, scope, openapi, testing, decisions]
---

# Project Roadmap: lara-spec-first

This document owns the sequencing. What each feature is, and why it was designed that way, lives in the document that
owns the subject; what lands when, and in which order, is decided here.

Two rules keep it usable:

- **A checked box is behavior with tests behind it.** Not a design that has been agreed on, and not a document that
  describes one. [Where the code is today](#where-the-code-is-today) is therefore verifiable rather than aspirational,
  which is the only reason a roadmap in a repository this early is worth reading at all.
- **A feature document names its phase, and this file is where the phase comes from.** A guide whose banner says a
  feature has no phase is a gap in _this_ document, not in that one.

## Where the code is today

The package reads a specification, normalizes it, and hands out its own types. It generates nothing and registers no
route.

### What runs

- [x] **Package structure.** `composer.json`, PSR-4 autoloading, the service provider with package discovery, and the
      development toolchain (Docker, Pest, Pint, Larastan, `just`).
- [x] **The reading pipeline**, five steps in the order
      [openapi-support](../guide/openapi-support.md#reading-a-document) fixes and explains: decode, detect, shape,
      cycles, remote references. `SpecDocumentReader` produces a `ParsableSpecDocument`, and nothing reaches the parser
      until all five have passed.
- [x] **The version strategy.** One class per OpenAPI minor version behind
      [one interface](../guide/openapi-support.md#handling-30-and-31-the-version-strategy), plus the factory that
      dispatches on the detected version. Pinned by a conformance class that writes one contract as 3.0 and as 3.1 and
      requires both to normalize identically.
- [x] **The parser integration.** `OperationExtractor` is the crossing point: references are resolved there and only
      there, and nothing `cebe\openapi\` returns leaves the class.
- [x] **The `Contract\` types.** `Operation`, `HttpMethod`, `PathTemplate`, `Audience`, `Lifecycle` and
      `SecurityRequirement`. The [lifecycle extensions](../guide/lifecycle.md) are read with their defaults resolved and
      an unrecognized value refused rather than silently taken as the default, and an operation's `security`
      distinguishes inheriting the document's requirements from explicitly requiring nothing.
- [x] **Configuration.** The publishable config file, and
      [`ConfigurationMerger`](https://github.com/Gcob/lara-spec-first/blob/main/src/Configuration/ConfigurationMerger.php)
      merging the package defaults deeply beneath whatever an application published, so a nested key added in a later
      release does not arrive missing.
- [x] **The remote-reference allowlist, in its strict half.**
      [`remote_references.allowed_hosts`](../guide/remote-references.md#the-setting) is empty by default and every
      remote reference is refused before the parser can fetch it. Naming a host throws, because the fetching behind it
      is not built and a setting that is read and ignored tells whoever set it that it took effect.
- [x] **The architecture assertions.** The parser is contained to `Parsing\`, and `Contract\` is forbidden from knowing
      anything about the layer that produced it. Both are Pest `arch()` tests rather than conventions to remember.

### What does not exist yet

Three empty namespaces name the gap precisely: `Generation\`, `Console\` and `Routing\`. There is no Artisan command, no
generated PHP, and no registered route. Five of the six blocks in `config/lara-spec-first.php` are marked `TODO` in the
file itself and are inert, which the file says out loud rather than leaving to be discovered.

## Phase 1: The Foundation

_Goal: one operation in a specification becomes one route that answers, and everything the package will not honor is
said out loud before anything runs._

**The slice is deliberately thin, and stops before persistence.** No `x-model`, no response DTO, no generated
`FormRequest`: those need each other, and pulling them in would mean this phase never demonstrates the whole path from a
contract to an HTTP response. What it proves instead is the property every later phase rests on: the contract reaches
the code, and a gap in it is loud.

### Generating and registering

- [ ] **`Routing\`: the service provider registers the generated routes.** It **does not read the spec**, at boot or
      ever. Explicit over dynamic: see
      [the runtime never sees the spec](../guide/code-generation.md#the-runtime-never-sees-the-spec). The generated
      registration has to be serializable, which is a concrete requirement rather than a hope, and confirming it against
      a real `php artisan route:cache` run is part of this item.
- [ ] **`spec:build`, in its Phase 1 form:** resolve the specification and emit the routes and the generated
      controllers. Idempotent, ordered, and it never writes outside its own directories. That last property is the
      [invariant](../guide/code-generation.md#the-invariant-a-build-never-destroys-human-work) stated without a clause
      precisely so that it can be tested as one.
- [ ] **The two-class seam.** One controller per operation carrying one `routeAction`, generated `final` unless the
      operation declares [`x-controller`](../guide/controllers.md#the-specification-decides-what-is-customizable), plus
      the thin `SpecController` base and its `middleware()` method. This is what "abstract controllers" means in this
      phase: the seam a developer's own class attaches to, with no persistence behind it.
- [ ] **Every generated file explains itself.** The [source map](../guide/code-generation.md#the-source-map) (the JSON
      pointer the file came from) and the
      [docblock norm](../guide/code-generation.md#every-generated-file-explains-itself) (provenance, findings,
      navigation), both emitted unconditionally and both asserted by the generator's own tests. It ships with the first
      generated file rather than after it: retrofitting a convention across a generated tree is an audit, writing it
      into the first emitter is a paragraph.
- [ ] **Rename and orphan detection.** Comparing the pointers in the existing generated tree against the ones the new
      build would emit is what turns a class-not-found into an instruction naming the old name, the new one, and
      [the files that reference it](../guide/code-generation.md#how-it-says-it). It depends on the source map above and
      on nothing else, which is why it belongs in the same phase.
- [ ] **`spec:make`: the only command that creates a file the developer will own.** It scaffolds a named operation, or a
      whole `--tag`, or `--all`, never as a side effect of a build. `spec:build` itself never scaffolds; it
      [names the commands to run](../guide/code-generation.md#the-build-names-the-command-instead-of-running-it). This
      item includes the `x-controller` insertion prompt and the verification that makes it safe: the edit happens on a
      copy, the copy is read back through the normal pipeline, and nothing is written unless the resulting operations
      are identical but for the extension just added.
- [ ] **An unimplemented operation answers `501`,** from a package-provided handler that names the `spec:make` command
      to run. See [501](../guide/code-generation.md#an-unimplemented-operation-answers-501). It is also the seam the
      Phase 2 mock plugs into, so getting its position right now costs nothing later.

### Reading, reporting, refusing

- [ ] **Remote reference vendoring.** The allowlist already refuses; what this needs is the fetch itself, the vendored
      copy committed beside the specification, and the resolution of the reference against that copy rather than the
      network. [Frozen by default](../guide/code-generation.md#remote-references-during-a-build-frozen-by-default): the
      build reaches the network only when a flag says so, and a missing vendored copy is an error naming that flag. See
      [remote references](../guide/remote-references.md).
- [ ] **A conformance suite over the reading engine, organized by equivalence class.** **Not routine coverage, but a
      deliberate answer to a risk already observed.** Two defects with no symptom have been found in the OpenAPI parser
      within days of first use, on a surface no wider than paths and references: a pure `$ref` cycle exhausts memory
      instead of raising, and `components.pathItems` loses an endpoint without reporting anything. Both are recorded in
      [parser caveats](../guide/openapi-support.md#parser-caveats), and neither would have been prevented by putting an
      interface in front of the parser: an adapter guards against _swapping_ a dependency, where what has actually gone
      wrong is the dependency _being wrong_. Behavior is therefore what gets pinned.

    The suite partitions the input space rather than accumulating examples, so that coverage can be argued instead of
    hoped for: by version, with the same contract written as 3.0 and as 3.1 and required to normalize identically (the
    version strategy's entire promise, and the one class that already exists in `tests/Conformance/`); by reference form
    (local, cross-file, blocked, cyclic, recursive schema, and each form a Path Item reference can take); by the
    positions where OpenAPI mixes data with specification; by document shape (empty, no paths, webhooks-only,
    components-only); and by failure class, keeping document faults, package limits and parser defects distinct in the
    assertions the way [the doctor](../guide/doctor.md#two-kinds-of-finding-never-mixed) keeps them distinct in its
    report.

    Every defect found in the parser earns a permanent case, so the list of what we know about it can only grow. And the
    suite ends up being what an adapter was wanted for: **the acceptance criteria a replacement parser would have to
    meet.** An interface would only prove a substitute compiles; this proves one behaves.

- [ ] **`spec:doctor`**, which is `nginx -t` for your contract: what the package will honor, what it will not, and the
      routing table that results. It belongs in this phase rather than with the Phase 2 developer experience, because it
      is what makes "the spec is the source of truth" verifiable rather than asserted. See
      [the doctor](../guide/doctor.md). The Phase 1 sections are the ones whose inputs exist: configuration, document
      validity, version, references, support findings, routing outcome, drift, installation, and the lifecycle rules
      below.
- [ ] **The lifecycle rules in the doctor.** `deprecated: true` requiring `x-sunset`, a sunset in the past or
      approaching, an unrecognized `x-lifecycle` value, the `beta` listing, and the protection report counting how many
      _public_ operations are actually `stable`. The data these rules read is [already extracted](#what-runs); what is
      missing is the reporting. See [the doctor rules](../guide/lifecycle.md#the-doctor-rules-that-follow).
- [ ] **`security` is reported, not enforced, and the report says so in those words.** Enforcement is
      [Phase 2](#authorization-the-contract-can-express), and a phase that registers routes without it must not let a
      consumer mistake a documented promise for a kept one. So Phase 1 owes an operation whose contract declares
      `security` a finding stating that the package does not yet apply it, and that finding is
      [not collapsible and not acknowledgeable away](../guide/doctor.md#acknowledging-changes-behavior-not-just-noise):
      an endpoint the contract describes as protected being unprotected is the one place where being annoying is the
      correct behavior.

## Phase 2: The generated pipeline, mocks and the driver features

_Goal: prove the thesis. For an ordinary CRUD endpoint, the route, the form request, the controller and the DTO are all
derived from the contract, and the only thing a developer writes is the model and the business logic that model
carries._

Not less typing for its own sake: less surface where the code and the contract can quietly disagree. Everything here is
`spec:build` and `spec:doctor` doing more, never a new command, with two exceptions that say so explicitly (`spec:watch`
and the mock server).

### The pipeline

- [ ] **Generated request validation.** One `FormRequest` per operation, derived from the request body and parameter
      schemas, with `PUT` requiring the full body where `PATCH` makes fields optional. It is what supplies `$validated`
      to everything below, so it comes first.
- [ ] **Response DTOs.** `final readonly`, generated from the response schema, with
      [no abstract layer to extend](../guide/code-generation.md#response-dtos) because a value object mirroring the
      contract has no behavior of its own to grow. Whether `spatie/laravel-data` becomes a dependency or only an
      influence is [`stack.md`](./stack.md)'s row to settle, in the same change that installs or declines it.
- [ ] **DTO factories.** One generated per DTO, mapping by name, with a default that covers the ordinary case.
      Overridden by a class that `extends` it, found by scanning
      [the directories a project declares](../guide/code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares)
      and nothing else, with exactly one override per factory and a hard error naming both classes when two claim one.
- [ ] **`x-model` and the CRUD defaults.** The `HasModel` interface with its `InteractsWithModel` trait, the
      [empty marker interface](../guide/controllers.md#the-detected-crud-semantic-is-a-marker-interface-deliberately-empty)
      naming the semantic the build detected, the generated create, update, delete and read bodies, and route-model
      binding driven by the type hint `x-model` supplies. Plus the doctor check that only makes sense once writes are
      generated: comparing a model-aware operation's validated fields against the bound model's mass-assignment rules,
      because Eloquent drops the rest without raising.
- [ ] **RFC 8594 `Sunset` headers from the generated code.** The lifecycle keys are already read and the doctor already
      enforces them; this is the third thing they buy, and it needs a response path to attach to, which is why it lands
      here rather than in Phase 1. Declared once in the spec, enforced in CI, advertised over HTTP, with nobody writing
      that code.
- [ ] **The sanitized public copy of the specification.** Off unless a project
      [names a disk](../guide/code-generation.md#where-the-public-copy-goes), so Phase 1 publishes nothing by default
      and leaks nothing. It lands here because `x-model` is what makes the private document genuinely sensitive, and
      because the work is larger than it looks: excluding `x-audience: internal` operations outright, then pruning
      transitively what they orphan (components, emptied path items, dangling tags), and reporting what was removed.

### Authorization the contract can express

- [ ] **`security` becomes an authorization check.** The `securitySchemes` name matched to a Laravel guard
      [by nomenclature](../guide/security.md#scheme-names-are-a-naming-contract-with-your-guards), the one `final`
      built-in middleware asking one question, the `HasSecurityScopes` interface the authenticated model implements, and
      the requirement resolved into the generated route at build time rather than read from the spec per request. The
      Phase 1 "not enforced" finding is deleted in the same change, and the doctor's
      [security section](../guide/doctor.md#what-it-checks) becomes real: a scheme with no matching guard, a scheme type
      the middleware cannot enforce, a model missing the interface.
- [ ] **Decide which scheme types reduce to "the model has a scope".** `apiKey`, `http bearer` and `oauth2` are the
      clear fits; `mutualTLS` and the details of `openIdConnect` may not be answerable by one middleware at all, and a
      scheme it cannot enforce is a case for
      [acknowledgement](../guide/doctor.md#acknowledged-limits-the-consumers-opt-out), never a silent pass.

### The driver features

The [driver mechanism](../guide/drivers.md) lands with the two features that need it rather than ahead of them, because
an extension point designed without a second implementation in front of it is a guess. Both features are extras that
remove redundancy: nothing breaks without them.

- [ ] **The driver mechanism itself,** and above all its registration API, which is public API surface under
      [rule 4](../guide/openapi-support.md#the-four-rules) and has to be decided once for every driver-based feature at
      once. Each feature publishes an interface for the contract and an abstract class for the boring half.
- [ ] **Pagination.** The `laravel` built-in driver, the envelope DTO per paginated operation beside its item and
      metadata DTOs, the `getPaginator()` and `respondWithCollection()` seams (only the first of which depends on
      `x-model`), and the doctor finding for a paginated response whose operation declares no pagination parameters. See
      [pagination](../guide/pagination.md).
- [ ] **Rate limiting.** The `headers` and `extension` built-in drivers, windows first-class from the first release
      because a dimension added later costs a major, the normalized `reset` spelling, and the doctor finding for 429
      declarations that are not structurally identical across operations. One thing has to be decided before the adapter
      is more than an interface: whether what reads it is
      [build-time enforcement or a runtime relay](../guide/rate-limiting.md#open-what-the-adapters-answer-actually-powers).
      See [rate limiting](../guide/rate-limiting.md).

### Mocks and the design loop

- [ ] **Faker-based mocking for operations with no implementation.** It replaces the body of the
      [`501` handler](../guide/code-generation.md#an-unimplemented-operation-answers-501) rather than adding a
      mechanism: same route, same handler position, a better answer. Its hard part is not Faker, it is the
      [parser caveat](../guide/openapi-support.md#parser-caveats) that hands 3.1 schema keywords back as raw arrays with
      unresolved `$ref`, which is where this feature will actually be spent.
- [ ] **A mock server driven by the spec:** serve the whole contract with conforming responses, with no application
      behind it. Distinct from the fallback above, which fills the gaps in a real application, and the first execution
      context that legitimately reads a specification outside a build. It is where
      [the runtime never sees the spec](../guide/code-generation.md#the-runtime-never-sees-the-spec) gets the
      enumeration of contexts it deliberately deferred.
- [ ] **`spec:watch`:** the design loop, rebuilding on change and allowed to fetch references that `spec:build`
      deliberately refuses to. A separate command because a running process states intent every time and dies with the
      terminal, where a config key would quietly follow you into CI. Its one hard rule is that it must never produce
      output `build` would not. See [watching](../guide/code-generation.md#watching-specwatch).
- [ ] **Spec-driven test data,** so that testing an endpoint does not start by writing a factory. The schema already
      states the shape, the constraints and often the examples. **Where this stops matters and must be said plainly:** a
      schema describes shapes, not domain truth. Referential integrity, business invariants and database constraints are
      not in it. Spec-driven data can replace a factory for HTTP-level and mock-server tests; it cannot replace one for
      tests that persist to a database.
- [ ] **Versioning directories (`v1/`, `v2/`).** Carried over from the first roadmap and **listed here as an intention
      rather than a decision**: no document owns it, which under
      [one topic, one file](../contributing/documentation.md#one-topic-one-file) means there is nothing to implement
      against yet. It either earns a design and a home, or it is dropped from the roadmap. Deciding which is itself the
      task.

## Breaking-change enforcement

_Goal: a stable operation cannot break without someone deciding to break it._

Deliberately not slotted into a phase: a rule that fails somebody's build has to be right before it ships, and the
breaking-change table is large enough to deserve its own body of work rather than being smuggled into a release. See
[lifecycle](../guide/lifecycle.md#unstable-by-default-and-what-stable-costs-us).

- [ ] Diff the specification against its previously committed version, read from git rather than from a separate file
      the build writes, normalizing 3.0/3.1 differences in memory before comparing. The doctor's
      [baseline check](../guide/doctor.md#what-it-checks) is the prerequisite: without it, a shallow clone or an
      untracked specification compares against nothing and reports a clean run for the wrong reason.
- [ ] The breaking-change table, direction-aware for requests and responses, versioned as public API in its own right.
- [ ] Fail the build on a breaking change to a `stable` operation, naming the `info.version` bump that would make it
      legitimate. Enforcement as instruction rather than as an obstacle.
- [ ] Report the two ways a promise can be revoked without a break being visible: demoting an operation from `public` to
      `internal`, and the exclusion from the published copy that demotion causes, which is the most breaking change
      there is for whoever was already calling it. Whether either merely reports or requires the same `info.version`
      bump is open.

## Before the first tag

_Goal: publish nothing we would have to break._

Also outside the phases, because these are not features and none of them blocks the others. What they share is that
every one of them gets more expensive the moment the package is on Packagist.

- [ ] **The CI test matrix.** PHP 8.3 / 8.4 / 8.5 against Laravel 12 / 13, six valid combinations with no `exclude`
      block, plus the lowest-dependency run. The docs workflow already exists; this does not. It is the `Planned` CI row
      in [`stack.md`](./stack.md).
- [ ] **Freeze the public names.** Under [rule 4](../guide/openapi-support.md#the-four-rules) every one of these becomes
      a compatibility contract on publication, and each is currently marked open in the document that owns it: the
      config keys (generated path and namespace, the override scan, the publish block, `pagination` and `rate_limiting`
      and every key inside their mappings), the Artisan command signatures and their flags, the controller interface,
      trait and method names, the exception class names, the vendored directory and the refetch flag, and the driver
      registration API. Settling them before the first release costs nothing; after it, each one costs a major.
- [ ] **Close the support-matrix rows a first release cannot leave `Open`.** Chiefly: whether a document containing
      `trace` fails to load or only the operation is refused, whether a non-conforming path parameter name is rejected
      absolutely or has an escape hatch for specs the consumer does not own, and what happens to `options` and `head`.
      Each is behavior a consumer writes code against.
- [ ] **A command reference document.** [The doctor](../guide/doctor.md) already defers its usage details to one, and
      three more commands arrive before the first tag.
- [ ] **Publish to Packagist** as `gcob/lara-spec-first`, and cut the first tag. The `Planned` distribution row in
      [`stack.md`](./stack.md).

## Phase 3: Legacy Bridge & Ecosystem

_Goal: turn an existing Code-First Laravel app into a Spec-First one, quickly, simply, and above all reliably._

The hard part of adopting Spec-First is not the new code, it is the app you already have. An established API has its
contract scattered across controllers, form requests, and resources, and nobody wants to retype it into YAML by hand.

The plan is to use existing Code-First tooling (`Scramble`, `L5-Swagger`) **once**, as an on-ramp: extract a spec from
the code you already run, then flip the direction of truth so the spec leads from that point on. It is a one-way door,
not a permanent round-trip.

Three properties matter, in this order:

- **Reliable.** You should be able to trust that the extracted spec actually describes what your API does today,
  _before_ you hand it the keys. A migration you cannot verify is not a migration.
- **Simple.** Adoptable route by route, never a big-bang rewrite. That is the shape
  [`spec:make --tag=`](../guide/code-generation.md#the-build-names-the-command-instead-of-running-it) already has, which
  is not a coincidence: adopting tag by tag was designed for this phase.
- **Fast.** The boring parts should be mechanical.

- [ ] Tooling to bootstrap a spec from an existing Code-First app, and to verify it against real behavior before
      cutover. _(Design in progress, more to come.)_
- [ ] First-class integration with `Spectator` for automated contract testing in CI/CD pipelines. Its `stack.md` row is
      `Planned` and moves to `Decided` in the same change that installs it.
- [ ] **The driver ecosystem.** A
      [driver is worth publishing as a package](../guide/drivers.md#drivers-are-meant-to-be-shared) because it carries
      structure and no project's field names, and the set of pagination and rate-limit conventions in the wild is larger
      than this package should ever ship. What is owed here is a naming convention for community drivers, and a doctor
      that names the resolved driver for each feature including third-party ones.
- [ ] Comprehensive documentation and real-world migration examples.
