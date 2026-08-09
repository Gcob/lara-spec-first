---
title: OpenAPI Support
audience: Users, contributors and agents
covers: >
    What the package honors of the OpenAPI specification and what it does not,
    the support levels and their compatibility promise, how OpenAPI 3.0 and 3.1
    differences are handled, the parser caveats behind those limits, and the
    Laravel constraints the package deliberately does not fight.
read_before: >
    Implementing anything that reads a spec, registers a route, or changes what
    the package accepts from a specification file.
tags: [ openapi, compatibility, scope, versions, decisions ]
---

# OpenAPI Support

This document is the reference for one question: **given a valid OpenAPI document, what does
`lara-spec-first` actually do with it?**

It exists because "supports OpenAPI 3.0 and 3.1" is not a truthful claim on its own. No tool in any
ecosystem honors the whole specification, and a Spec-First package that quietly ignores half of a
contract is worse than one that never claimed to read it — the whole promise is that the spec is the
source of truth.

> **Nothing described here is implemented yet.** The project is in Phase 1 of the
> [Roadmap](./ROADMAP.md) and the package does not parse a spec or register a route. This document
> states the **intent** for the first release and the reasoning behind it. Rows marked `Open` are
> genuinely undecided and must not be presented as settled — the same discipline
> [`STACK.md`](./STACK.md) applies to its own Status column.

## The four rules that govern this document

**1. Parsing is not honoring.** The parser reads both 3.0.x and 3.1.x. The package *honors* a
subset. Every promise this project makes must be about behavior, never about a version number. "We
support 3.1" is meaningless; "we register routes from `paths`, and we reject `trace`" is a promise.

**2. Silence is the enemy.** A construct the package does not honor must produce a diagnostic. If a
spec declares something and the package ignores it without a word, the document has stopped being the
source of truth and nobody finds out until production. This is the single most important rule here,
and it is the reason the [support levels](#support-levels) distinguish *ignored with a diagnostic*
from *ignored*.

**3. Opinionated, and we own it.** Where the specification leaves a choice open, this package makes
one and states it, rather than inventing configuration for every fork in the road. A stated opinion
a consumer can plan around beats a flexible behavior nobody can predict. See the
[project philosophy](../README.md#stack--philosophy).

**4. Support levels are a compatibility contract.** Once published, this matrix is part of the public
API surface, exactly like class names and config keys:

* Moving a row **towards** more support (`Rejected` to `Ignored`, `Ignored` to `Partial`, `Partial`
  to `Supported`) is a **minor** release. It cannot break a spec that worked before.
* Moving a row **away** from support is a **major** release. Someone's contract stops being honored.
* Changing *how* a `Supported` row behaves — the route order, the controller naming convention, the
  parameter mapping — is also **major**. Consumers have code written against it.

## Support levels

Five levels, and every one of them except `Supported` says something out loud:

| Level            | Meaning                                                   | Behaviour                                                                                                                              |
|------------------|-----------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| **Supported**    | The construct is read and honored.                        | Nothing to report.                                                                                                                     |
| **Partial**      | Honored under stated conditions; outside them, it is not. | Diagnostic when a document leaves the supported subset. The conditions are written in this file, never left to the reader to discover. |
| **Ignored**      | Read, understood, deliberately not acted on.              | Diagnostic. The spec stays valid and the package keeps working, but the consumer is told the construct had no effect.                  |
| **Rejected**     | The package cannot honor it and will not pretend to.      | Hard error. The spec does not load.                                                                                                    |
| **Out of scope** | Not this package's concern at all.                        | No diagnostic. Listed here only so nobody has to wonder.                                                                               |

`Ignored` and `Rejected` differ in blast radius, not in honesty. A `deprecated: true` flag that
changes nothing is an `Ignored` row — annoying to be told about, fatal to nobody. A `trace` operation
the package cannot route is `Rejected`, because loading the spec anyway would leave a documented
endpoint silently missing.

### Where the diagnostics go: the doctor

Rule 2 has an obvious failure mode. A package that reports every unhonored construct at boot is a
package that shouts on every request, and a tool that shouts constantly gets its output filtered out —
at which point the diagnostic exists and nobody reads it, which is rule 2 defeated by its own
enforcement.

**Decision: the diagnostics get their own command. `nginx -t`, not a log line.**

The model is deliberate. `nginx` does not warn you about your configuration on every request; it gives
you one command that answers *"is this configuration good?"*, exits non-zero when it is not, and is
therefore the thing you run before a reload and the thing CI runs on every commit. That is the shape
this package needs, for the same reason: a Spec-First package's most valuable output is not "your
request failed", it is **"here is exactly what your contract will and will not do once loaded"** —
and that answer is worth reading *before* the app runs, not during.

This is not a Phase 2 developer-experience nicety. **It is the enforcement mechanism for rule 2, so it
ships with the first thing that reads a spec** — see the [Roadmap](./ROADMAP.md).

#### The contract

* **One command, not two — validation is centralized.** "Is my document valid OpenAPI" and "will this
  package honor it" are genuinely different questions, and they are deliberately answered in one
  place. Every check that reads the spec shares one report format, one exit contract, and one place to
  add the next check. Splitting them would mean two commands to wire into CI, two output formats to
  parse, and a standing question about which one to run. Validity is the doctor's first section, not a
  separate command.
* **The exit code is the API.** Zero means the spec is fully honored. Non-zero means it is not. That
  single property is what makes it usable as a CI gate and a pre-deploy gate, and it is what stops the
  report from becoming decorative.
* **Machine-readable output.** Real specs are large, and the report grows with them. A `--json` flag
  lets CI annotate a pull request instead of dumping a wall of text, and lets tooling — including AI
  agents, which is a first-class use case for this package — consume the findings without parsing
  prose. Cheap to design in, awkward to retrofit.
* **Read-only, always.** It never writes a cache, never touches the database, never mutates state.
  Safe to run anywhere, including production, which is precisely where you want it when a contract
  behaves differently than staging.
* **Report everything, not the first failure.** `nginx -t` stops at the first syntax error because a
  config file is a linear thing. A support matrix is not: a developer needs the full list of what was
  ignored in one pass, otherwise adoption becomes a whack-a-mole loop.
* **Every finding names the document position.** File, JSON pointer, and the
  [support level](#support-levels) that applies. A finding you cannot locate is a rumor.
* **It reports the outcome, not only the problems.** The resolved routing table — which routes will
  exist, in which order, mapped to which controller and method, and whether that controller exists —
  is the single most useful thing this command can print. Most runs will be clean, and a command that
  prints nothing on success teaches the developer nothing about what the spec actually did.

#### Planned flags

Centralizing every check in one command means that command needs a way to narrow what it runs. The
intended surface, all provisional:

| Flag              | Purpose                                                                                |
|-------------------|----------------------------------------------------------------------------------------|
| `--json`          | Machine-readable findings, for CI annotation and for tooling that consumes the report. |
| `--check=syntax`  | Document validity only: is this valid OpenAPI.                                         |
| `--check=honored` | Support findings only: what this package will and will not honor.                      |

The doctor takes no flag that lets it reach the network. It has no reason to: every remote reference
is already [vendored locally](#a-remote-reference-is-a-dependency-not-a-cache-entry), so a blocked or
missing reference is diagnosed by reading the working tree, and fetching belongs to the build. A
`--bypass-allowlist` escape hatch, if one is ever wanted, belongs on the fetching path, not here.

Two constraints on any flag added here, and they are the reason this list is short:

* **A filtered run's exit code covers only what it ran.** `--check=syntax` exiting zero means the
  document is valid, not that the package will honor it. The report says which checks were skipped, on
  every run, so a green exit is never mistaken for a full pass.
* **A flag that changes what the package would actually do makes the run non-representative, and the
  report must say so.** A clean run under such a flag does not predict a clean boot, so the report
  labels it, and CI has no business using it. Without that label, a flag quietly breaks the one
  property that makes the exit code worth anything.

#### Two kinds of finding, never mixed

A single command answering both questions only works if the report never blurs them. **"Your document
is broken" and "this package cannot honor your document" are different problems, with different
owners, different fixes, and different urgency** — and a developer who cannot tell them apart at a
glance will treat the whole report as noise.

| Class              | Means                                                                                                                                           | Who fixes it                                                           | How                                                                                                                        |
|--------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------|
| **Document fault** | The document is not valid OpenAPI, or is internally inconsistent: schema violations, an unresolvable `$ref`, a path parameter declared nowhere. | The spec author.                                                       | Fix the document. There is no other option, and the package will not guess.                                                |
| **Package limit**  | The document is correct. This package does not honor the construct.                                                                             | Us, eventually — it is a roadmap item, not a defect in their contract. | The consumer changes the spec, waits for support, or [acknowledges the limit](#acknowledged-limits-the-consumers-opt-out). |

The distinction has to survive into the output, not just the prose here: separate sections, distinct
labels, and — proposed — **distinct exit codes**, so a CI pipeline can gate hard on document faults
while treating package limits as a softer signal. `0` clean, one code for faults, another for limits.
The exact numbers are open; the fact that they differ should not be.

The rule that follows from this: **a package limit is never reported as if the consumer made a
mistake.** They wrote a valid contract. We are the ones who cannot serve all of it yet, and the
message says so.

#### What it checks

Provisional, and expected to grow one section per honored construct:

| Section           | Answers                                                                                                                                                                                                                         |
|-------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Configuration     | Are the spec files found and readable? Which allowlist and options are in effect?                                                                                                                                               |
| Document validity | Is this valid OpenAPI? The parser does not answer this in its library API — see [parser caveats](#parser-caveats) — so the doctor owns it.                                                                                      |
| Version           | Which version was detected, and which [strategy](#handling-30-and-31-the-version-strategy) will handle it.                                                                                                                      |
| References        | Unresolved `$ref`, references blocked by the [allowlist](#remote-references-and-the-domain-allowlist), recursion.                                                                                                               |
| Support findings  | Every `Partial`, `Ignored` and `Rejected` construct in the document, with its position.                                                                                                                                         |
| Routing outcome   | The routes that will be registered, in order, with their targets — plus shadowing, where an earlier templated path swallows a later literal one.                                                                                |
| Security          | Operations declaring `security` that the package does not enforce. This gets its own section rather than a line among others, because it is the one finding that can turn a documented-as-protected endpoint into a public one. |

#### Open questions on the doctor

* **The command name.** One command is settled; what it is called is not. Command signatures are
  public API surface under [rule 4](#the-four-rules-that-govern-this-document), so this is worth
  getting right once. No name is committed to anywhere yet — the [Roadmap](./ROADMAP.md) describes the
  command without naming it, deliberately.
* **The exit codes.** That document faults and package limits exit differently is settled. The numbers
  are not.
* **What still happens at boot.** `Rejected` fails at boot unless
  [acknowledged](#acknowledged-limits-the-consumers-opt-out), in which case the construct is skipped —
  the doctor is a check, not a substitute for refusing to load a spec the package cannot serve.
  Whether anything *below* `Rejected` surfaces at boot at all, or whether the doctor is the only
  channel, is still open and interacts with how the spec is cached.

When a command reference document exists, the usage details move there and this section keeps only the
reasoning. It lives here for now because the doctor is what makes the
[support levels](#support-levels) mean anything.

### Acknowledged limits: the consumer's opt-out

**Decision: a consumer can declare, in configuration, that they accept a limit — and the package then
stops treating it as a problem.**

Rule 2 assumes the reader can act on the diagnostic. Often they cannot. The spec comes from another
team, from a vendor, from a generator that always emits the same construct, and it is not theirs to
change. For that developer a permanent, unfixable warning is not information — it is a broken window,
and the first thing they will look for is the switch that turns the whole package quiet. Better to
hand them a precise switch than to let them reach for a blunt one.

Acknowledgement is not the same as suppression, and the difference is the whole design:

* **It is enumerated, never global.** You list the specific constructs you accept. There is no
  "silence everything" option, because a spec that grows a new unhonored construct next month must
  still speak up — you never acknowledged *that* one.
* **It stays visible.** Acknowledged items still appear in the doctor's report, in their own section,
  not folded into a count and not hidden. They stop *failing*; they do not stop *existing*. A
  configuration file nobody ever reads again is how accepted debt becomes forgotten debt.
* **Stale acknowledgements are themselves a finding.** When a construct you acknowledged no longer
  occurs in your spec, or the package has since grown support for it, the doctor says so, and you
  delete the line. Without this, the config only ever accumulates.
* **It is reviewable.** It lives in the application's config file, in version control, in diffs. The
  closest analogue in this ecosystem is a PHPStan baseline: an explicit, versioned list of accepted
  debt, where new violations still fail the build.

#### Acknowledging changes behavior, not just noise

This is the part that must never be understated in the documentation we ship. Acknowledging a
`Rejected` construct is what allows the spec to load at all — so it is also the moment the construct
is **dropped**. Acknowledge a `trace` operation and the document still describes an endpoint that will
answer 404. That is a legitimate choice, and it is the consumer's to make, but the report has to state
the consequence in those terms rather than reporting a clean bill of health.

Which is why **security acknowledgements are never collapsed.** A consumer may accept that the package
does not enforce a declared security scheme — that is their call — but every affected operation is
listed individually, on every run, forever. This is the one place where being annoying is the correct
behavior: the finding is that an endpoint the contract describes as protected is not.

#### Open questions on acknowledgement

* **Granularity.** Per construct (`trace: accepted` everywhere) covers the vendor-generator case that
  motivates the feature. Per construct *and* location covers the one weird endpoint. Starting at the
  construct level and widening later is a **minor** release; the reverse is not.
* **Whether a reason string is required.** Requiring a justification on each entry is friction that
  pays for itself the day someone reads the config a year later and cannot remember why. It is also
  the kind of opinionated requirement [rule 3](#the-four-rules-that-govern-this-document) invites.
* **The scope of a `Rejected` acknowledgement** — skipping the offending operation is the leading
  answer, with a `rejected_behavior: skip | fail` style option if the choice turns out to be worth
  giving away. Deliberately left to be settled against real code rather than in the abstract: the
  difference between the two only becomes concrete once there is a spec loader to watch.
* **The config keys themselves.** Public API surface. Not chosen.

## Handling 3.0 and 3.1: the version strategy

**Decision: the version differences live behind a strategy, one concrete implementation per OpenAPI
minor version.** Not conditionals sprinkled through the codebase.

The reasoning:

* **The differences are not cosmetic.** 3.1 changed the schema dialect, not just the syntax. Handling
  it with `if (version === '3.1')` scattered across the parser, the router and the mocker means every
  future version multiplies the branches in every file that ever touched a schema.
* **A third version is a matter of when, not if.** Adding `OpenApi32Strategy` should mean writing one
  class, not auditing the codebase for version checks.
* **Readability is a real argument, not a bonus.** A reader who wants to know how 3.1 nullability
  works should find it in one file, not by grepping for a version string.

### What is shared and what is version-specific

The seam matters more than the pattern. Putting it in the wrong place duplicates work for no gain:

| Concern                                  | Where it belongs     | Why                                                                                                                                                                                                         |
|------------------------------------------|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Reading the file, YAML/JSON decoding     | **Shared**           | Byte-level work, identical in both versions. You also cannot know the version until the document is decoded — `openapi: 3.1.0` is a field *inside* the file. Dispatch happens after decoding, never before. |
| Multi-file loading and `$ref` resolution | **Shared**           | JSON Reference mechanics are the same. The [allowlist](#remote-references-and-the-domain-allowlist) is a security policy, not a version concern.                                                            |
| Schema interpretation                    | **Version-specific** | This is where 3.0 and 3.1 genuinely disagree. See the table below.                                                                                                                                          |
| Document shape rules                     | **Version-specific** | `paths` is required in 3.0 and optional in 3.1; `webhooks` exists only in 3.1.                                                                                                                              |
| Route registration                       | **Shared**           | It consumes the normalised output, and must never see a version number.                                                                                                                                     |

The test of a correct seam: **nothing downstream of the strategy knows which version was loaded.** If
the router or the mocker has to ask, the normalization is incomplete.

### The differences the strategy must absorb

| Subject                                 | 3.0.x                                    | 3.1.x                                         | Note                                                                                                                 |
|-----------------------------------------|------------------------------------------|-----------------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| Nullability                             | `nullable: true`                         | `type: [string, "null"]`                      | Two spellings of one idea. The normalised form must be one thing, and the choice of which is ours to make and state. |
| `type`                                  | A single string                          | A string or an array of strings               | The parser declares this `Type::STRING` (`Schema.php:92`) and does not enforce it, so an array arrives unchecked.    |
| `exclusiveMinimum` / `exclusiveMaximum` | Boolean, modifying `minimum` / `maximum` | A number, standing on its own                 | One property name, two semantics (`Schema.php:154-161`).                                                             |
| Examples                                | `example` (singular, any value)          | `examples` (an array)                         | Both keys can appear. Precedence is ours to define.                                                                  |
| Schema dialect                          | A JSON Schema subset                     | Full JSON Schema 2020-12                      | The widest gap, and the source of most of the [parser caveats](#parser-caveats).                                     |
| `paths`                                 | Required                                 | Optional                                      | A valid 3.1 document with only `webhooks` and `components` must produce zero routes without failing.                 |
| `webhooks`                              | Does not exist                           | Top-level                                     | See the [matrix](#the-support-matrix).                                                                               |
| `$ref` siblings                         | Ignored                                  | `summary` and `description` allowed alongside | The parser models this (`Reference.php`), we must decide whether we honor it.                                        |

### Open question: does the file parser belong inside the concrete strategy?

**Status: Open.** Raised, not settled.

The instinct behind the question is right — a single parser for both versions *is* a constraint. But
the constraint is not where it first appears. The YAML decoding is version-agnostic: `symfony/yaml`
turns bytes into a PHP array without an opinion about OpenAPI, and you cannot dispatch on a version
you have not decoded yet. The real limit is
[cebe's **object model**](#parser-caveats) — its `Schema` class is shaped for 3.0 and lets 3.1
keywords through as raw arrays.

So the recommendation is: **decode once in shared code, dispatch on the decoded version, and let the
strategy own the interpretation.** But — and this is the part worth designing for now — the strategy
interface should be expressed in **our own types, never in `cebe\openapi\` types**. That way a future
version whose needs cebe cannot meet can bring its own parser behind the same interface, without the
rest of the package noticing. The seam is cheap to build now and expensive to retrofit.

This needs a decision before the first strategy is written, and it belongs in
[`STACK.md`](./STACK.md) once made.

## Parser caveats

Every row below was verified against the vendored `devizzent/cebe-php-openapi`. These are not
criticisms of the library — it is a low-level reader and it says so. They are the constraints our
layer has to compensate for.

| Caveat                                                                                                                               | Evidence                             | What it means for us                                                                                                                                                                                                                                                                                                                                                                                                |
|--------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `validate()` is structural only. Validation against the OpenAPI JSON Schema exists **only in the CLI tool**, not in the library API. | Parser `README.md`, `OpenApi.php:86` | We cannot rely on the parser to reject a malformed spec. Rejecting bad documents is our job, and it is a headline feature of a Spec-First package.                                                                                                                                                                                                                                                                  |
| Unknown properties are kept **as raw PHP arrays**, silently.                                                                         | `SpecBaseObject.php:142-144`         | 3.1 JSON Schema keywords (`const`, `prefixItems`, `$defs`, `if`/`then`/`else`, `patternProperties`, `dependentSchemas`, `unevaluatedProperties`, `contentMediaType`) survive, but never as `Schema` objects — **and any `$ref` inside them is never resolved**. The most dangerous caveat on this page, because nothing fails: you get a value, it is just wrong. It will bite the Faker mocker in Phase 2 hardest. |
| `type` is declared as a string but 3.1 arrays pass through unvalidated.                                                              | `Schema.php:92`                      | Our code must accept `string\|array` everywhere it touches a type, or normalise it at the boundary.                                                                                                                                                                                                                                                                                                                 |
| `exclusiveMinimum` / `exclusiveMaximum` accept both booleans and numbers with no version check.                                      | `Schema.php:154-161`                 | The same property means different things depending on the document version. Only the strategy should ever see the raw form.                                                                                                                                                                                                                                                                                         |
| A Path Item's `$ref` is special-cased and is not a normal `Reference`.                                                               | `PathItem.php:73-78`                 | Path-level `$ref` needs its own handling in the router.                                                                                                                                                                                                                                                                                                                                                             |
| Remote `$ref` by URL is resolved transparently.                                                                                      | `Reader.php`, `ReferenceContext`     | Network I/O during boot, and an SSRF surface. See [below](#remote-references-and-the-domain-allowlist).                                                                                                                                                                                                                                                                                                             |
| `paths` is not required for 3.1 documents.                                                                                           | `OpenApi.php:91`                     | Zero routes is a valid outcome, not an error.                                                                                                                                                                                                                                                                                                                                                                       |

## Remote references and the domain allowlist

**Decision: remote `$ref` targets are resolved only from an explicitly allowlisted set of domains,
configured by the consuming application.**

An OpenAPI document that can pull `$ref: https://example.com/schemas/user.yaml` turns a config file
into a network client running with the application's credentials and network position. Two problems,
not one:

* **Security.** An untrusted or compromised spec reaches whatever the application server can reach —
  internal services, cloud metadata endpoints. The spec file is usually reviewed like documentation,
  not like code that makes outbound requests.
* **Availability.** A remote host that is slow or down becomes a boot failure for an application that
  has nothing to do with it.

An allowlist is the right shape because it is flexible where teams genuinely need it — an internal
schema registry, a shared contract repository — and closed everywhere else. Rules:

* **Empty by default.** No allowlist means no remote references. Local files keep working; a project
  that never uses remote `$ref` never sees this feature.
* **A blocked reference is an error, never a skip.** A silently unresolved `$ref` is an unhonored
  contract, which rule 2 forbids.
* **The exception names the offending reference, the document position, and the config key to
  change.** "Unresolvable reference" is a support ticket; the full triple is a fix.
* **Matching is on the host, exactly.** No wildcard subdomains, no partial matches — `evil-example.com`
  must never satisfy an entry for `example.com`.

**Status: decided in principle, unimplemented.** The config key name and the exception class are
public API surface and are not yet chosen.

### A remote reference is a dependency, not a cache entry

Repeated network calls for the same reference are waste, so something has to hold the fetched
document. The word for that something is **not cache**, and the word is the design.

A cache is expendable by definition. You may clear it at any time, it may expire on its own, and
nothing about your application changes when it does — that is the contract of the word. None of that
is true here. A remote `$ref` supplies part of your API contract: drop it and your application can no
longer describe, route, or validate what it serves. **A remote reference is a dependency**, in the
full sense the word carries in this ecosystem, and it should be handled the way dependencies are
handled: a vendored copy under version control, and an explicit act to change it.

Two things follow immediately, and each kills a config option that looked reasonable:

* **No duration.** A TTL means the contract can change at a moment nobody chose. Some Tuesday at
  14:03 an entry expires, the upstream document has moved on, and the application serves a different
  contract than it did a minute earlier — no deploy, no commit, no review. **A contract changes when
  someone ships a change, not when a timer fires.** A source of truth that varies with wall-clock time
  is not a source of truth. No dependency manager resolves your dependencies again because an hour
  passed, and neither does this.
* **No cache store.** Routes are registered while the framework boots, so whatever the registration
  reads has to be available before the container is warm. Depending on Redis to know which routes
  exist is a boot-time network dependency in the request path, for data that never changes between
  deploys — and a shared store lets two servers in the same release disagree about the contract, which
  is precisely the failure a spec exists to prevent.

#### No lock file: git is the lock

The dependency analogy suggests a lock file. It should not be taken, and working out why sharpens the
whole design.

A lock file exists to pin something mutable to something exact — a version range to a resolved
version, a resolved version to a content hash. **Here there is no version to pin**: a `$ref` is a URL,
and the only thing that could be recorded is a hash of bytes we are already about to store on disk. So
the lock would restate, less usefully, what the vendored copy already is.

Less usefully, because of the review argument. A lock file diff says *a hash changed*. A vendored
document diff says *this response gained a required field*. For an API contract, the second is the
entire value, and only committed copies produce it. Git already content-addresses every file, so the
integrity check the lock was there to provide is a property of the repository, not something to
reimplement.

**Decision: the vendored copies are committed, and there is no lock file.** Two consequences to design
around:

* **The local path must encode where the document came from**, since nothing else records provenance.
  A layout mirroring host and path — one directory per host, the URL's path beneath it — is
  self-documenting, greppable, and reviewable. Fetching from the same URL twice must land in the same
  place, or the whole scheme leaks.
* **Detecting local tampering requires a refetch.** Without a recorded hash, a hand-edited vendored
  copy is caught by code review rather than by the tool. That is an honest trade, not an oversight:
  the edit does show up in a diff, and re-fetching is what the update path does anyway.

**Open.** URLs with query strings, very long paths, and case-insensitive filesystems all complicate a
path-mirroring layout. Solvable, unsolved.

#### Borrowing the dependency-manager shape

The parts of the pattern worth taking, and only these:

| Piece                            | What it does here                                                                                                                                                                                                                                                                                                                                                |
|----------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Vendored copies, committed**   | The fetched documents, on disk, in version control. Once they exist, boot resolves everything locally and **the runtime never touches the network** — not on a miss, not on the first request after a restart, never, because there is no lookup to miss. Their diffs are how a change to your API contract shows up in a pull request instead of in production. |
| **Frozen by default**            | The build never reaches the network on its own. A fresh clone builds offline; a missing vendored copy is an error naming the flag to run, never an implicit fetch.                                                                                                                                                                                               |
| **Fetching is one explicit act** | Adding a reference and refreshing one are both deliberate, flagged operations, because both can change your contract. See [the build](./CODE-GENERATION.md#remote-references-during-a-build-frozen-by-default).                                                                                                                                                  |
| **Integrity by repository**      | Upstream changed under you? The refetch produces a diff, in a commit, in a review. A remote `$ref` is third-party content that shapes your public API surface, and treating it as untrusted input is the lesson every package ecosystem learned the expensive way — git gives us that property without a mechanism of our own.                                   |

The [allowlist](#remote-references-and-the-domain-allowlist) still governs every fetch, but its threat
model shrinks to almost nothing: outbound requests now happen only inside an explicit, human- or
CI-triggered operation, never in a request.

#### Where the analogy stops

We are not building a dependency manager, and the borrowed vocabulary must not drag in the rest of it:

* **No version constraints, no resolution, no solver.** A `$ref` is a URL, not a package with a
  version range. There is nothing to negotiate and no conflicts to resolve.
* **No registry, and nothing to publish.**
* **No lock file** — [git already is one](#no-lock-file-git-is-the-lock).
* **One divergence, deliberate: the vendored copies are committed.** Composer can leave `vendor/` out
  of version control because Packagist guarantees a published version is immutable. Nothing guarantees
  that about `https://example.com/schemas/user.yaml` — it can change or vanish tomorrow. Committing
  the copies is what makes an old release still deployable, and it is why no lock file is needed.

**Open.** The names of the vendored directory and of the refetch flag are public API surface under
[rule 4](#the-four-rules-that-govern-this-document) and are not chosen. Also open: whether a fetched
document that itself contains remote references is followed — transitive fetching, with the allowlist
applying at every hop — or refused at depth one.

#### Vendoring is one part of the build

Vendoring makes the *inputs* local. Turning those inputs into routes, controllers and validation is a
separate job, and both belong to the same command — see
[`CODE-GENERATION.md`](./CODE-GENERATION.md). Do not conflate the two: vendoring alone already
guarantees no network at boot, whatever the build does afterwards.

What is settled here regardless: **the doctor reads, it never writes** — it is
[read-only by contract](#the-contract) — and its report names which sources it read, because a doctor
that silently checks something other than what runs is worse than no doctor.

## Lifecycle: the extensions this package defines

OpenAPI can say an operation is `deprecated`. It cannot say what comes before deprecation, and it
cannot say *when the endpoint disappears* — which is the only part a consumer can actually plan
around. **Decision: the package defines three extension keys to close that gap.**

| Key           | Where     | Value                                                                                   |
|---------------|-----------|-----------------------------------------------------------------------------------------|
| `x-audience`  | Operation | `public` or `internal`. Absent means `public`.                                          |
| `x-lifecycle` | Operation | `beta` or `stable`. Its default [depends on the audience](#two-keys-one-discriminator). |
| `x-sunset`    | Operation | The date the endpoint stops being served.                                               |

### Two keys, one discriminator

How strong a promise an operation carries, and who it is promised to, are two different questions.
Two axes, so two keys, with the audience acting as the discriminator that sets the other's default:

| `x-audience`       | Default `x-lifecycle` | Reasoning                                                                                                                      |
|--------------------|-----------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `public` (default) | `beta`                | Somebody outside this codebase may depend on it. The stage is a claim you have to make.                                        |
| `internal`         | none                  | Same application on both ends. Requiring a lifecycle stage on every internal route is ceremony for a promise nobody asked for. |

Three constraints make this safe rather than merely convenient:

**`x-audience` itself defaults to `public`.** This is not a coin flip — it is the same principle as
defaulting to `beta`. Omission must never be the cheaper path to less protection, because omission is
what happens when a spec is imported, generated, or written in a hurry. Declaring an endpoint internal
is an act; being treated as public is what happens by default.

**A missing lifecycle is the absence of a claim, not a prohibition.** An internal endpoint can still
declare `x-lifecycle: stable`, and it can still be `deprecated` with a full
[`x-sunset` treatment](#the-doctor-rules-that-follow) — internal consumers deserve a removal date as
much as anyone. They simply do not need a promise on every route to get one.

**Demoting `public` to `internal` is reported.** This is the hole the composite otherwise opens: once
a breaking change to a `stable` operation fails the build, flipping its audience to `internal` makes
the failure disappear. That may be entirely legitimate — an endpoint really can stop being public —
but it is *revoking a promise*, and a promise cannot be revoked silently in a package built on
contracts. The report names it, in the same spirit as labelling a
[non-representative run](#planned-flags). Whether it merely reports or requires the same
`info.version` bump a break would is **open**.

One consequence worth having: the doctor's protection report counts **public** operations only. A
monolith with two hundred internal routes should not have its *0 of 47 public operations are stable*
finding drowned by endpoints that were never promised to anyone.

### `deprecated` is native, and stays out of `x-lifecycle`

OpenAPI already has `deprecated: true` on an operation. Putting `deprecated` in `x-lifecycle` as well
would create a second place to state one fact — the failure this whole package exists to prevent — so
it is not in the value set at all. **`x-lifecycle` says how strong the promise is. `deprecated` says
the operation is going away. They are independent, and both can be true.**

Removing it costs nothing and buys two things. There is no agreement rule to write, because there is
nothing to disagree with. And an operation can be `stable` *and* `deprecated`, which is not a
contradiction but the normal, well-behaved case: a promise being honoured right up to its stated
removal date is exactly what a good deprecation looks like.

`x-lifecycle` is then a binary, and what it adds to OpenAPI is one word the specification has no way
to express: whether an operation is promised at all.

### The doctor rules that follow

| Rule                                             | Why                                                                                                                                                                                  |
|--------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `deprecated: true` requires `x-sunset`           | Your idea, and the strongest rule here. A deprecation with no end date is a wish. Requiring the date turns "we should remove this someday" into a commitment with a review attached. |
| `x-sunset` in the past is a finding              | You are serving an endpoint you promised to remove. Nothing else in the system will ever notice.                                                                                     |
| `x-sunset` approaching is a warning              | With a configurable horizon, so it lands in CI while there is still time to act.                                                                                                     |
| An unrecognised `x-lifecycle` value is a finding | Extensions are untyped by nature: `x-lifecycle: stabel` is silent everywhere else in the toolchain.                                                                                  |
| `beta` operations are listed                     | The unstable surface of an API, on one screen, is worth printing even when nothing is wrong.                                                                                         |

### Unstable by default, and what `stable` costs us

**Decision: a public operation with no `x-lifecycle` is `beta`.** You cannot claim a stability
guarantee by omission — claiming one is an act. This is the right default for the same reason the
[allowlist](#remote-references-and-the-domain-allowlist) is empty by default: the permissive state is
the one you should have to opt out of, not into.

| Value                        | Means                                  | What the build does                    |
|------------------------------|----------------------------------------|----------------------------------------|
| `beta` (default when public) | Not yet promised to anyone.            | Permissive. Change it freely.          |
| `stable`                     | A production consumer depends on this. | **A breaking change fails the build.** |
| none (default when internal) | No claim made, and none expected.      | Permissive by intent, not by neglect.  |

Orthogonal to all of them, `deprecated: true`
[remains native](#deprecated-is-native-and-stays-out-of-x-lifecycle) and can accompany any value.

`stable` is worth promoting to the moment one production consumer exists — unless that consumer
knowingly signed up for instability, which is what [`x-audience: internal`](#two-keys-one-discriminator)
records.

Four consequences, because a rule that fails a build has to be right:

**1. Failing on a breaking change requires a baseline, and the baseline should be committed.** You
cannot diff a contract against nothing. The previous generated tree is not enough — it only captures
what we generate, and plenty of breaking changes (a response field becoming optional, an enum losing a
member) never reach a signature. The honest answer is that **the resolved specification is itself a
build artifact and belongs in version control**, with every `$ref` inlined. It then does three jobs at
once: it is the baseline for this rule, it makes the *effective* contract reviewable in a pull request
rather than the fragments it was assembled from, and it is the artifact the runtime loads. One file,
three problems.

**2. "Breaking" is directional, and the direction inverts between request and response.** This is
where implementations get it wrong, so it has to be a written table rather than a judgement call:
adding a required *request* field breaks clients; adding a *response* field usually does not. Removing
a response field breaks them; removing an optional request field usually does not. Widening an enum
breaks response consumers and helps request senders; narrowing it does the opposite. That table is
itself public API under [rule 4](#the-four-rules-that-govern-this-document) — a change to what counts
as breaking changes whose build fails — and it is large enough to deserve its own phase rather than
being smuggled into the first release.

**3. The escape hatch already exists in the document: `info.version`.** A build that only says *you
broke a stable operation* is an obstacle. A build that says **this change requires `info.version` to
go from `2.4.1` to `3.0.0`, and will pass once it does** has turned enforcement into instruction. It
needs no config, no flag and no acknowledgement entry — the contract carries its own version, and
deliberately breaking one becomes indistinguishable from publishing a major, which is exactly what it
should be. Breaking on purpose stays possible; breaking by accident stops being.

**4. The doctor must report how much of the API is actually protected.** A specification imported from
elsewhere has no `x-lifecycle` anywhere, so every public operation defaults to `beta` and the strongest
rule in this document is silently off for the whole API. *47 public operations, 0 stable* is a finding
under [rule 2](#the-four-rules-that-govern-this-document): protection that is off must never look like
protection that passed.

### The runtime payoff

This is what makes these keys worth defining rather than documenting a convention: **the generated
code can act on them.** RFC 8594 standardises a `Sunset` HTTP header carrying exactly this date, and
the IETF has a companion `Deprecation` header in draft. A contract that declares a sunset can
therefore produce an endpoint that announces it on every response, to every client, without anyone
writing that code.

Declared once in the spec, enforced in CI by the doctor, and advertised over HTTP by the generated
controller — that is the whole thesis of this package applied to a single field.

**Open.** The date format (`x-sunset` should almost certainly be RFC 3339, converted to the HTTP-date
the header requires); whether emitting the headers is on by default; the warning horizon; and the
collision risk of a name as generic as `x-lifecycle`, which another tool may already define
differently. A vendor prefix would remove the ambiguity at the cost of every consumer typing it.

## Laravel constraints we do not fight

This package is a layer built **on** Laravel, not a replacement for its router. Where Laravel's model
and OpenAPI's model disagree, the framework wins and we document the consequence. Working around the
router to honor an exotic corner of the specification buys one feature and pays for it with every
future Laravel upgrade.

That is a deliberate trade, and it is the reason some rows in the matrix say `Rejected` rather than
`Supported`.

### Route order: the spec file's order is the route order

**Decision.** `/users/me` and `/users/{id}` both match the request `GET /users/me`. OpenAPI defines no
priority between them. Laravel resolves the first route registered. Rather than invent a sorting rule
and hide it, **routes are registered in document order, and the document order decides**.

This is [rule 3](#the-four-rules-that-govern-this-document) in action: put the specific path above the
templated one in your YAML, and it wins. The alternative — sorting literal segments ahead of
templated ones — is defensible, but it means a consumer reading their own spec top to bottom cannot
predict their own routing. An opinion you can see beats a heuristic you cannot.

The cost is stated plainly: **reordering keys in a spec file can change routing behaviour.** That is
a documented property of this package, not a bug report.

### `trace` cannot be routed

`Illuminate\Routing\Router::$verbs` is `GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS`
(`Router.php:136`). There is no TRACE. The parser accepts `trace` on a Path Item
(`PathItem.php:60`), so a valid document can contain an operation Laravel cannot register.

Per the rule above, we do not work around the router. The operation is `Rejected`.

**Open:** whether an entire document containing `trace` fails to load, or the operation alone is
refused while the rest of the document still loads. The second is friendlier; the first is more
honest. Either way [the doctor](#where-the-diagnostics-go-the-doctor) names the operation and its
position. Undecided.

### Parameter names are a naming contract, not a mapping problem

OpenAPI puts almost no constraint on a path parameter's name. `{user-id}`, `{user.id}` and
`{a_very_long_and_descriptive_parameter_name}` are all legal. The router underneath Laravel is far
narrower, and it does not fail politely.

**What actually happens.** Laravel compiles its routes through Symfony's compiler, which finds
placeholders with `#\{(!)?([\w\x80-\xFF]+)\}#` (`vendor/symfony/routing/RouteCompiler.php:118`). `\w`
is `[A-Za-z0-9_]`, so `{user-id}` is **not recognised as a placeholder at all** — it stays literal
text, and the route matches only a URL containing the characters `{user-id}`. No exception, no
warning, an endpoint that silently 404s forever.

It gets worse in a specific way. Laravel's own `Route::compileParameterNames()` uses
`/\{(.*?)\}/` (`vendor/laravel/framework/src/Illuminate/Routing/Route.php:535`), which accepts
anything. **The framework's introspection and the framework's matcher disagree**: `parameterNames()`
reports `user-id` as a parameter of a route that can never bind it. Anything reasoning about the route
— including our own doctor, if it asked Laravel instead of the spec — would be told a comfortable lie.

And a hard ceiling nobody expects: a placeholder longer than **32 characters** throws a
`DomainException` from the same compiler (`RouteCompiler.php:145`). OpenAPI has no such limit, so a
descriptive parameter name is a crash rather than a mismatch.

Given that, the hard parts were never in the conversion:

* **The result is public API.** The converted name appears in controller method signatures and in
  route model binding. Changing the convention later is a **major** release under
  [rule 4](#the-four-rules-that-govern-this-document).
* **Conversion is not injective.** `{user-id}` and `{user.id}` in the same path both reduce to
  `user_id`. A collision must be an error, not a last-writer-wins.
* **The mapping has to survive the round trip.** Reading a parameter back out of the request, and
  validating it against the spec, both need the original OpenAPI name. The two names have to be kept
  side by side, not converted and forgotten.

**Leaning: reject, do not convert.** Rule 3 and the position that
[API design is a skill](./CODE-GENERATION.md#naming-and-the-rename-problem) point the same way — a
build that refuses `{user-id}` and says *rename this parameter to `user_id` in your specification* is
teaching a real constraint of the platform, once, at build time. A build that silently converts is
maintaining a shadow naming scheme forever, and the developer still meets it the first time they read
a generated signature. The 32-character ceiling is not negotiable either way and must be checked
before the route is ever compiled.

**Open:** whether that rejection is absolute or has an escape hatch for specs the consumer does not
own — the case [acknowledgement](#acknowledged-limits-the-consumers-opt-out) exists for.

### Still to discuss

The following were raised in analysis and are **not yet reviewed**. They are listed so nothing is
lost, not because they are decided:

* Several parameters in one path segment — `/files/{name}.{ext}` is legal OpenAPI and fragile in
  Laravel.
* `servers`, including path-level and operation-level overrides and server variables with `enum` and
  `default`: what becomes the route prefix.
* `operationId`: optional in the specification, not guaranteed unique, not guaranteed to be a valid
  PHP identifier — and it is what names the generated controller and method. Public API surface. The
  naming and rename questions are now answered in
  [`CODE-GENERATION.md`](./CODE-GENERATION.md#naming-and-the-rename-problem); what remains here is how
  a missing or unusable `operationId` is reported.
* `security` to middleware mapping. The most dangerous row in the matrix: registering a route without
  applying the authentication the spec declares publishes an endpoint the contract says is protected.
* `php artisan route:cache`: generated routes must be serialisable, which means controller strings
  and no closures. Plus the cost of parsing a spec on every boot.
* `webhooks` (3.1) and `callbacks`: not routes on this server.
* `HEAD` and `OPTIONS`: Laravel handles HEAD for GET automatically, so an explicit `head` operation
  conflicts.
* Phase 2 territory: `style` and `explode`, `deepObject`, `multipart/form-data` with `encoding`,
  multi-media-type content negotiation, `discriminator`, `links`, `xml`.

## The support matrix

**Every row is provisional.** `Open` means the discussion has not happened; the other values are
current intent for the first release, not shipped behaviour.

### Document

| Construct                               | Level     | Note                                                                                                                       |
|-----------------------------------------|-----------|----------------------------------------------------------------------------------------------------------------------------|
| `openapi` 3.0.x                         | Supported | Dispatches to the 3.0 [strategy](#handling-30-and-31-the-version-strategy).                                                |
| `openapi` 3.1.x                         | Supported | Dispatches to the 3.1 strategy.                                                                                            |
| Any other version                       | Rejected  | Including 2.x. Convert before adopting.                                                                                    |
| `info`, `externalDocs`, `tags`          | Ignored   | Documentation metadata with no routing effect.                                                                             |
| `jsonSchemaDialect` (3.1)               | Open      | Only the default dialect is realistically honorable.                                                                       |
| `servers`                               | Open      | See [still to discuss](#still-to-discuss).                                                                                 |
| `security` (root)                       | Open      |                                                                                                                            |
| `webhooks` (3.1)                        | Open      |                                                                                                                            |
| `x-` extensions                         | Ignored   | Preserved by the parser and readable, but the package acts on none of them — except the two it defines itself, below.      |
| `x-audience`, `x-lifecycle`, `x-sunset` | Open      | The extensions this package defines. Rules and doctor checks: [lifecycle](#lifecycle-the-extensions-this-package-defines). |

### Paths and operations

| Construct                               | Level     | Note                                                                                                                                                                 |
|-----------------------------------------|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `paths`                                 | Supported | The source of every registered route.                                                                                                                                |
| Path templating `{param}`               | Partial   | Conforming names only, pending the [naming decision](#parameter-names-are-a-naming-contract-not-a-mapping-problem).                                                  |
| Several parameters in one segment       | Open      |                                                                                                                                                                      |
| Path Item `$ref`                        | Open      | Special-cased by the parser (`PathItem.php:73-78`).                                                                                                                  |
| `get`, `post`, `put`, `patch`, `delete` | Supported |                                                                                                                                                                      |
| `options`                               | Open      | Conflicts with Laravel's own handling.                                                                                                                               |
| `head`                                  | Open      | Laravel derives HEAD from GET automatically.                                                                                                                         |
| `trace`                                 | Rejected  | [Not routable](#trace-cannot-be-routed).                                                                                                                             |
| Route ordering                          | Supported | [Document order wins](#route-order-the-spec-files-order-is-the-route-order).                                                                                         |
| `operationId`                           | Open      | Names the generated controller and method.                                                                                                                           |
| `deprecated`                            | Open      | No effect on routing, but it is the authoritative lifecycle state and it gates the `x-sunset` rule. See [lifecycle](#lifecycle-the-extensions-this-package-defines). |
| `callbacks`                             | Open      |                                                                                                                                                                      |

### Parameters, bodies, responses

| Construct                                         | Level   | Note                                        |
|---------------------------------------------------|---------|---------------------------------------------|
| `parameters` (`path`)                             | Partial | Needed for routing. Validation is Phase 2.  |
| `parameters` (`query`, `header`, `cookie`)        | Ignored | No routing effect. Phase 2 for validation.  |
| `style`, `explode`, `allowReserved`, `deepObject` | Open    | Phase 2.                                    |
| `requestBody`                                     | Ignored | Phase 2.                                    |
| `responses`                                       | Ignored | Phase 2, and the input to the Faker mocker. |
| `links`                                           | Open    |                                             |
| Media type `encoding`                             | Open    | Phase 2.                                    |

### Schemas

| Construct                                                                                                                                                     | Level | Note                                                                                                                                                                              |
|---------------------------------------------------------------------------------------------------------------------------------------------------------------|-------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Core JSON Schema subset shared by 3.0 and 3.1                                                                                                                 | Open  | Phase 2 defines how far this goes.                                                                                                                                                |
| `nullable` / `type: [..., "null"]`                                                                                                                            | Open  | Normalised by the strategy; the normal form is not chosen.                                                                                                                        |
| `allOf`, `oneOf`, `anyOf`, `not`                                                                                                                              | Open  |                                                                                                                                                                                   |
| `discriminator`, `xml`                                                                                                                                        | Open  |                                                                                                                                                                                   |
| 3.1-only keywords (`const`, `prefixItems`, `$defs`, `if`/`then`/`else`, `patternProperties`, `dependentSchemas`, `unevaluatedProperties`, `contentMediaType`) | Open  | Blocked on a [parser caveat](#parser-caveats): the parser hands these back as raw arrays with unresolved `$ref`. Whatever we decide, it cannot be "read them from cebe and hope". |

### References and security

| Construct                        | Level     | Note                                                                                                |
|----------------------------------|-----------|-----------------------------------------------------------------------------------------------------|
| Local `$ref` within the document | Supported |                                                                                                     |
| `$ref` to another local file     | Supported | Multi-file specs are a Phase 1 goal.                                                                |
| Remote `$ref` by URL             | Partial   | [Allowlisted domains only](#remote-references-and-the-domain-allowlist); anything else is an error. |
| Recursive `$ref`                 | Open      | The parser stops at a depth rather than resolving infinitely.                                       |
| `securitySchemes` and `security` | Open      | See [still to discuss](#still-to-discuss).                                                          |

## Changing this document

The rules in [`DOCUMENTATION.md`](./DOCUMENTATION.md) apply, plus two specific to this file:

* **A row changes in the same commit as the behaviour it describes.** This matrix is the definition of
  done for any change to what the package accepts from a spec. A behaviour change that leaves the
  matrix stale is an incomplete change, exactly as
  [`AGENTS.md`](../AGENTS.md#every-change-lands-in-three-places) states.
* **State the release impact when moving a row.** Rule 4 makes direction meaningful: say whether the
  move is minor or major, in the commit message.
