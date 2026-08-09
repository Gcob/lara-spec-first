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
| **Partial**      | honored under stated conditions; outside them, it is not. | Diagnostic when a document leaves the supported subset. The conditions are written in this file, never left to the reader to discover. |
| **Ignored**      | Read, understood, deliberately not acted on.              | Diagnostic. The spec stays valid and the package keeps working, but the consumer is told the construct had no effect.                  |
| **Rejected**     | The package cannot honor it and will not pretend to.      | Hard error. The spec does not load.                                                                                                    |
| **Out of scope** | Not this package's concern at all.                        | No diagnostic. Listed here only so nobody has to wonder.                                                                               |

`Ignored` and `Rejected` differ in blast radius, not in honesty. A `deprecated: true` flag that
changes nothing is an `Ignored` row — annoying to be told about, fatal to nobody. A `trace` operation
the package cannot route is `Rejected`, because loading the spec anyway would leave a documented
endpoint silently missing.

### Diagnostic modes

Because rule 2 conflicts with rule 3 in one place — a strict package is a loud package, and loud
packages get their warnings suppressed — the intent is a single configurable knob:

| Mode     | `Partial` / `Ignored` becomes   | Intended for                                        |
|----------|---------------------------------|-----------------------------------------------------|
| `strict` | An exception at boot.           | CI, and the default for new adopters.               |
| `warn`   | A logged warning plus a report. | Migrating an existing app route by route (Phase 3). |

`Rejected` is not affected by the mode. It is always an error.

**Status: proposed.** The config key names, the default mode, and where the report surfaces
(a `spec:validate` command is a Phase 2 item on the [Roadmap](./ROADMAP.md)) are all undecided.

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
the router or the mocker has to ask, the normalisation is incomplete.

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

**Open:** whether an entire document containing `trace` fails to load, or the operation is refused
individually under the [diagnostic mode](#diagnostic-modes). The second is friendlier; the first is
more honest. Undecided.

### Parameter names are a naming contract, not a mapping problem

OpenAPI allows `{user-id}` and `{user.id}`; Laravel route parameters must match
`[A-Za-z_][A-Za-z0-9_]*`. Converting one to the other is trivial — and that triviality is exactly
what makes it easy to get wrong, because the hard parts are not in the conversion:

* **The result is public API.** The converted name appears in controller method signatures and in
  route model binding. Changing the convention later is a **major** release under
  [rule 4](#the-four-rules-that-govern-this-document).
* **Conversion is not injective.** `{user-id}` and `{user.id}` in the same path both reduce to
  `user_id`. A collision must be an error, not a last-writer-wins.
* **The mapping has to survive the round trip.** Reading a parameter back out of the request, and
  validating it against the spec, both need the original OpenAPI name. The two names have to be kept
  side by side, not converted and forgotten.

**Open:** the exact conversion rule, and whether non-conforming names are converted at all or simply
`Rejected` with a message telling the author to rename the parameter in the spec. The second is more
in keeping with rule 3.

### Still to discuss

The following were raised in analysis and are **not yet reviewed**. They are listed so nothing is
lost, not because they are decided:

* Several parameters in one path segment — `/files/{name}.{ext}` is legal OpenAPI and fragile in
  Laravel.
* `servers`, including path-level and operation-level overrides and server variables with `enum` and
  `default`: what becomes the route prefix.
* `operationId`: optional in the specification, not guaranteed unique, not guaranteed to be a valid
  PHP identifier — and it is what names the generated controller and method. Public API surface.
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

| Construct                      | Level     | Note                                                                        |
|--------------------------------|-----------|-----------------------------------------------------------------------------|
| `openapi` 3.0.x                | Supported | Dispatches to the 3.0 [strategy](#handling-30-and-31-the-version-strategy). |
| `openapi` 3.1.x                | Supported | Dispatches to the 3.1 strategy.                                             |
| Any other version              | Rejected  | Including 2.x. Convert before adopting.                                     |
| `info`, `externalDocs`, `tags` | Ignored   | Documentation metadata with no routing effect.                              |
| `jsonSchemaDialect` (3.1)      | Open      | Only the default dialect is realistically honorable.                        |
| `servers`                      | Open      | See [still to discuss](#still-to-discuss).                                  |
| `security` (root)              | Open      |                                                                             |
| `webhooks` (3.1)               | Open      |                                                                             |
| `x-` extensions                | Ignored   | Preserved by the parser and readable, but the package acts on none of them. |

### Paths and operations

| Construct                               | Level     | Note                                                                                                                |
|-----------------------------------------|-----------|---------------------------------------------------------------------------------------------------------------------|
| `paths`                                 | Supported | The source of every registered route.                                                                               |
| Path templating `{param}`               | Partial   | Conforming names only, pending the [naming decision](#parameter-names-are-a-naming-contract-not-a-mapping-problem). |
| Several parameters in one segment       | Open      |                                                                                                                     |
| Path Item `$ref`                        | Open      | Special-cased by the parser (`PathItem.php:73-78`).                                                                 |
| `get`, `post`, `put`, `patch`, `delete` | Supported |                                                                                                                     |
| `options`                               | Open      | Conflicts with Laravel's own handling.                                                                              |
| `head`                                  | Open      | Laravel derives HEAD from GET automatically.                                                                        |
| `trace`                                 | Rejected  | [Not routable](#trace-cannot-be-routed).                                                                            |
| Route ordering                          | Supported | [Document order wins](#route-order-the-spec-files-order-is-the-route-order).                                        |
| `operationId`                           | Open      | Names the generated controller and method.                                                                          |
| `deprecated`                            | Ignored   | No runtime effect.                                                                                                  |
| `callbacks`                             | Open      |                                                                                                                     |

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
