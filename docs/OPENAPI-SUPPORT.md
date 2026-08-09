---
title: OpenAPI Support
audience: Users, contributors and agents
covers: >
    What the package honors of the OpenAPI specification and what it does not:
    the four rules that govern every such decision, the support levels and their
    compatibility promise, the construct-by-construct matrix, how OpenAPI 3.0
    and 3.1 differences are handled, the order in which a document is read and
    why it cannot change, the parser caveats behind these limits, and the
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

Four subjects grew out of this file and own themselves now. The [four rules](#the-four-rules) below
still govern all of them:

| Document                                         | Owns                                                             |
|--------------------------------------------------|------------------------------------------------------------------|
| [`DOCTOR.md`](./DOCTOR.md)                       | How any of this is reported, and how a consumer accepts a limit. |
| [`CONTRACT-ARTIFACT.md`](./CONTRACT-ARTIFACT.md) | The normalized form every comparison goes through.               |
| [`REMOTE-REFERENCES.md`](./REMOTE-REFERENCES.md) | A `$ref` that points at a URL.                                   |
| [`LIFECYCLE.md`](./LIFECYCLE.md)                 | How strong a promise each operation carries.                     |

## The four rules

**1. Parsing is not honoring.** The parser reads both 3.0.x and 3.1.x. The package *honors* a
subset. Every promise this project makes must be about behavior, never about a version number. "We
support 3.1" is meaningless; "we register routes from `paths`, and we reject `trace`" is a promise.

**2. Silence is the enemy.** A construct the package does not honor must produce a diagnostic. If a
spec declares something and the package ignores it without a word, the document has stopped being the
source of truth and nobody finds out until production. This is the single most important rule here,
and it is why the [support levels](#support-levels) below are six rather than two: each one exists to
say something specific out loud, or to be explicit that there is nothing to say.

**3. Opinionated, and we own it.** Where the specification leaves a choice open, this package makes
one and states it, rather than inventing configuration for every fork in the road. A stated opinion
a consumer can plan around beats a flexible behavior nobody can predict. See the
[project philosophy](../README.md#stack--philosophy).

**4. Support levels are a compatibility contract.** Once published, this matrix is part of the public
API surface, exactly like class names and config keys. It moves along **two independent axes**, and
conflating them is how a guarantee gets weakened without anyone noticing.

**How much of the contract is honored** — `Rejected`, then `Ignored` and `Deferred` together, then
`Partial`, then `Supported`. `Ignored` and `Deferred` share a rung deliberately: both mean *not acted
on*, and they differ only on the second axis.

* Moving a row **up** this ladder is a **minor** release. It cannot break a spec that worked before.
* Moving a row **down** is a **major** release. Someone's contract stops being honored.
* Changing *how* a `Supported` row behaves — the route order, the controller naming convention, the
  parameter mapping — is also **major**. Consumers have code written against it.

**What it does to the exit code** — this is not a ladder, and every move on it is **major, in either
direction.** Making a row noisier breaks pipelines that were green; making it quieter silently stops a
pipeline from catching something it used to catch. The second is the more dangerous of the two and the
easier to mistake for an improvement: `Ignored` to `Deferred` looks like generosity and is in fact the
removal of a gate. A package whose entire promise is that a contract cannot drift unnoticed does not
get to weaken its own detection in a minor release.

## Support levels

Six levels. Every one except `Supported` and `Out of scope` says something out loud, and only two of
them can make the [exit code](./DOCTOR.md#the-contract) non-zero:

| Level            | Meaning                                                                       | Behavior                                                                                                                               | Exit code                                                                                              |
|------------------|-------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| **Supported**    | The construct is read and honored.                                            | Nothing to report.                                                                                                                     | —                                                                                                      |
| **Partial**      | Honored under stated conditions; outside them, it is not.                     | Diagnostic when a document leaves the supported subset. The conditions are written in this file, never left to the reader to discover. | Non-zero **only when the document leaves the supported subset** — a spec that stays inside it is clean |
| **Ignored**      | In scope, present in the document, understood, and deliberately not acted on. | Diagnostic. The spec stays valid and the package keeps working, but the consumer is told the construct had no effect.                  | Non-zero                                                                                               |
| **Deferred**     | Recognized, support planned, not built yet.                                   | One summary line per document, never one per occurrence.                                                                               | —                                                                                                      |
| **Rejected**     | The package cannot honor it and will not pretend to.                          | Hard error. The spec does not load.                                                                                                    | Non-zero                                                                                               |
| **Out of scope** | Not this package's concern at all.                                            | No diagnostic. Listed here only so nobody has to wonder.                                                                               | —                                                                                                      |

**`Deferred` exists because the exit code has to stay reachable.** Without it, every construct the
package has not built yet is `Ignored`, every `Ignored` is a finding, and since `info` is mandatory in
every OpenAPI document, *no specification could ever exit zero* — which would kill the one property
that makes `spec:doctor` usable as a CI gate, and would push consumers to
[acknowledge](./DOCTOR.md#acknowledged-limits-the-consumers-opt-out) everything on day one, leaving them deaf when
real support arrives.

The distinction is about whose problem it is. `Ignored` says *your document says something this
package will not act on* — worth failing over. `Deferred` says *we have not built this yet* — a fact
about our roadmap, not a defect in your contract, and it does not get to fail your pipeline.

`Ignored` and `Rejected` differ in blast radius, not in honesty. A `deprecated: true` flag that
changes nothing is an `Ignored` row — annoying to be told about, fatal to nobody. A `trace` operation
the package cannot route is `Rejected`, because loading the spec anyway would leave a documented
endpoint silently missing.

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
| Multi-file loading and `$ref` resolution | **Shared**           | JSON Reference mechanics are the same. The [allowlist](./REMOTE-REFERENCES.md) is a security policy, not a version concern.                                                                                 |
| Schema interpretation                    | **Version-specific** | This is where 3.0 and 3.1 genuinely disagree. See the table below.                                                                                                                                          |
| Document shape rules                     | **Version-specific** | `paths` is required in 3.0 and optional in 3.1; `webhooks` exists only in 3.1.                                                                                                                              |
| Route registration                       | **Shared**           | It consumes the normalized output, and must never see a version number.                                                                                                                                     |

The test of a correct seam: **nothing downstream of the strategy knows which version was loaded.** If
the router or the mocker has to ask, the normalization is incomplete.

### The differences the strategy must absorb

| Subject                                 | 3.0.x                                    | 3.1.x                                         | Note                                                                                                                 |
|-----------------------------------------|------------------------------------------|-----------------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| Nullability                             | `nullable: true`                         | `type: [string, "null"]`                      | Two spellings of one idea. The normalized form must be one thing, and the choice of which is ours to make and state. |
| `type`                                  | A single string                          | A string or an array of strings               | The parser declares this `Type::STRING` (`Schema.php:92`) and does not enforce it, so an array arrives unchecked.    |
| `exclusiveMinimum` / `exclusiveMaximum` | Boolean, modifying `minimum` / `maximum` | A number, standing on its own                 | One property name, two semantics (`Schema.php:155-161`).                                                             |
| Examples                                | `example` (singular, any value)          | `examples` (an array)                         | Both keys can appear. Precedence is ours to define.                                                                  |
| Schema dialect                          | A JSON Schema subset                     | Full JSON Schema 2020-12                      | The widest gap, and the source of most of the [parser caveats](#parser-caveats).                                     |
| `paths`                                 | Required                                 | Optional                                      | A valid 3.1 document with only `webhooks` and `components` must produce zero routes without failing.                 |
| `webhooks`                              | Does not exist                           | Top-level                                     | See the [matrix](#the-support-matrix).                                                                               |
| `$ref` siblings                         | Ignored                                  | `summary` and `description` allowed alongside | The parser models this (`Reference.php`), we must decide whether we honor it.                                        |

### Where the parser sits: decided

The question was whether each concrete strategy should own its own file parser. The instinct behind it
is right — a single parser for both versions *is* a constraint — but the constraint is not where it
first appears. Decoding is version-agnostic: `symfony/yaml` turns bytes into a PHP array without an
opinion about OpenAPI, and you cannot dispatch on a version you have not decoded yet. The real limit is
[cebe's **object model**](#parser-caveats), whose `Schema` class is shaped for 3.0 and lets 3.1
keywords through as raw arrays.

**Decision: decode once in shared code, dispatch on the decoded version, and let the strategy own the
interpretation — with the strategy interface expressed in our own types, never in `cebe\openapi\`
ones.** A future version whose needs cebe cannot meet can then bring its own parser behind the same
interface without anything else noticing.

**And the containment is enforced, not merely intended.** The package is laid out so that one
namespace, and only one, may see the parser:

| Namespace     | Owns                                                                   |
|---------------|------------------------------------------------------------------------|
| `Parsing\`    | Reading a document, and the only place `cebe\openapi\` may appear.     |
| `Contract\`   | Our own types — what a strategy produces and everything else consumes. |
| `Generation\` | Emitting PHP.                                                          |
| `Console\`    | The commands.                                                          |
| `Routing\`    | What the service provider loads at boot.                               |

The architecture test in `tests/Unit/ArchitectureTest.php` asserts it directly:

```php
arch('the OpenAPI parser stays inside Parsing')
    ->expect('cebe\openapi')
    ->toOnlyBeUsedIn('Gcob\LaraSpecFirst\Parsing');
```

That is stricter than forbidding the parser to the request path, and simpler: there is one boundary to
state rather than a list of namespaces to keep current as the package grows.

## Parser caveats

Every row below was verified against the vendored `devizzent/cebe-php-openapi`. These are not
criticisms of the library — it is a low-level reader and it says so. They are the constraints our
layer has to compensate for.

Line numbers cite the versions in `composer.lock` at the time of writing — including
`laravel/framework` 13.x for the [Laravel constraints](#laravel-constraints-we-do-not-fight) below.
The behavior is what matters and it holds across the supported range; the line numbers may not.

| Caveat                                                                                                                               | Evidence                                                                                                                              | What it means for us                                                                                                                                                                                                                                                                                                                                                                                                                                   |
|--------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `validate()` is structural only. Validation against the OpenAPI JSON Schema exists **only in the CLI tool**, not in the library API. | Parser `README.md`, `OpenApi.php:86`                                                                                                  | We cannot rely on the parser to reject a malformed spec. Rejecting bad documents is our job, and it is a headline feature of a Spec-First package.                                                                                                                                                                                                                                                                                                     |
| Unknown properties are kept **as raw PHP arrays**, silently.                                                                         | `SpecBaseObject.php:142-144`                                                                                                          | 3.1 JSON Schema keywords (`const`, `prefixItems`, `$defs`, `if`/`then`/`else`, `patternProperties`, `dependentSchemas`, `unevaluatedProperties`, `contentMediaType`) survive, but never as `Schema` objects — **and any `$ref` inside them is never resolved**. The most dangerous caveat on this page, because nothing fails: you get a value, it is just wrong. It will bite the Faker mocker in Phase 2 hardest.                                    |
| `type` is declared as a string but 3.1 arrays pass through unvalidated.                                                              | `Schema.php:92`                                                                                                                       | Our code must accept `string\|array` everywhere it touches a type, or normalize it at the boundary.                                                                                                                                                                                                                                                                                                                                                    |
| `exclusiveMinimum` / `exclusiveMaximum` accept both booleans and numbers with no version check.                                      | `Schema.php:155-161`                                                                                                                  | The same property means different things depending on the document version. Only the strategy should ever see the raw form.                                                                                                                                                                                                                                                                                                                            |
| A Path Item's `$ref` is special-cased and is not a normal `Reference`.                                                               | `PathItem.php:73-78`                                                                                                                  | Path-level `$ref` needs its own handling in the router.                                                                                                                                                                                                                                                                                                                                                                                                |
| Remote `$ref` by URL is resolved transparently.                                                                                      | `Reader.php`, `ReferenceContext`                                                                                                      | Network I/O during boot, and an SSRF surface. See [below](./REMOTE-REFERENCES.md).                                                                                                                                                                                                                                                                                                                                                                     |
| `paths` is not required for 3.1 documents.                                                                                           | `OpenApi.php:91`                                                                                                                      | Zero routes is a valid outcome, not an error.                                                                                                                                                                                                                                                                                                                                                                                                          |
| A pure `$ref` cycle exhausts memory instead of raising.                                                                              | Verified: `A: {$ref: B}` / `B: {$ref: A}` under `RESOLVE_MODE_ALL` dies in `JsonPointer.php:108` with *Allowed memory size exhausted* | The parser does carry cycle checks (`Reference.php:324,330`), but this shape recurses past them. A malformed document takes the process down rather than producing a diagnostic — the one failure mode the doctor cannot report on, because it never gets to return. **Detecting `$ref` cycles is our job, before the document reaches the parser.** An ordinary recursive *schema* is fine; the two are [different things](#references-and-security). |

## Reading a document

Before any rule above can apply, a file has to become a document. Four steps, and **the order is the
design rather than an implementation detail** — each one is impossible before the one that precedes it,
and the last one is impossible after.

| Step       | What it does                                                                   | Why it sits there                                                                                                                                                                                                                          |
|------------|--------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Decode** | YAML or JSON into an array.                                                    | Nothing can be decided about bytes. One code path serves both formats, because YAML 1.2 is a superset of JSON — branching on the file extension would only add a way to reject a correctly written document for carrying the wrong suffix. |
| **Detect** | Read `openapi`, pick the [strategy](#handling-30-and-31-the-version-strategy). | The version is a field *inside* the file, so dispatch cannot happen any earlier than this.                                                                                                                                                 |
| **Shape**  | Check the root keys the version requires.                                      | Needs the version to be known: `paths` is required at 3.0 and optional at 3.1, and that single difference is the whole reason this step is version-specific.                                                                               |
| **Cycles** | Reject a `$ref` chain that never reaches content.                              | Last, and necessarily before the parser. See below.                                                                                                                                                                                        |

**Why the cycle check cannot move.** A pure reference cycle is the one document fault the parser does
not survive: it recurses past its own guards and exhausts memory rather than raising
([parser caveats](#parser-caveats)). Once the parser holds the document there is no exception left to
catch and no process left to report with — which makes it the only failure mode `spec:doctor` could
never tell you about, because it never returns. So the check runs on the decoded array, before
anything is handed over, and it cannot be folded into a wrapper around the parser.

The check knows only what a single file can tell it. References into another file or over the network
are skipped, since resolving them needs the vendored copies that a later step loads, so a cycle that
closes only across files is out of reach here. That limit is deliberate, and it is stated in a test
rather than in a comment.

**What comes out is narrow on purpose.** The result says the document *may be parsed* — not that it is
correct. It has not been validated against the OpenAPI schema, no `$ref` has been resolved, and the 3.0
and 3.1 spellings of the same idea are both still present exactly as written. Normalizing them is the
[contract artifact](./CONTRACT-ARTIFACT.md)'s job, and reporting what is wrong with the contents is
[the doctor](./DOCTOR.md)'s. Refusing to load and reporting a fault are different jobs, and only the
first one happens here.

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

This is [rule 3](#the-four-rules) in action: put the specific path above the
templated one in your YAML, and it wins. The alternative — sorting literal segments ahead of
templated ones — is defensible, but it means a consumer reading their own spec top to bottom cannot
predict their own routing. An opinion you can see beats a heuristic you cannot.

The cost is stated plainly: **reordering keys in a spec file can change routing behavior.** That is
a documented property of this package, not a bug report.

### `trace` cannot be routed

`Illuminate\Routing\Router::$verbs` is `GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS`
(`Router.php:136`). There is no TRACE. The parser accepts `trace` on a Path Item
(`PathItem.php:60`), so a valid document can contain an operation Laravel cannot register.

Per the rule above, we do not work around the router. The operation is `Rejected`.

**Open:** whether an entire document containing `trace` fails to load, or the operation alone is
refused while the rest of the document still loads. The second is friendlier; the first is more
honest. Either way [the doctor](./DOCTOR.md) names the operation and its
position. Undecided.

### Parameter names are a naming contract, not a mapping problem

OpenAPI puts almost no constraint on a path parameter's name. `{user-id}`, `{user.id}` and
`{a_very_long_and_descriptive_parameter_name}` are all legal. The router underneath Laravel is far
narrower, and it does not fail politely.

**What actually happens.** Laravel compiles its routes through Symfony's compiler, which finds
placeholders with `#\{(!)?([\w\x80-\xFF]+)\}#` (`vendor/symfony/routing/RouteCompiler.php:118`). `\w`
is `[A-Za-z0-9_]`, so `{user-id}` is **not recognized as a placeholder at all** — it stays literal
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
  [rule 4](#the-four-rules).
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
own — the case [acknowledgement](./DOCTOR.md#acknowledged-limits-the-consumers-opt-out) exists for.

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
* `php artisan route:cache`: mostly answered by
  [generating the routes](./CODE-GENERATION.md#the-runtime-never-sees-the-spec) rather than deriving
  them at boot. What remains is the concrete requirement that generated routes be serializable —
  controller strings, no closures — and confirming it against a real `route:cache` run.
* `webhooks` (3.1) and `callbacks`: not routes on this server.
* `HEAD` and `OPTIONS`: Laravel handles HEAD for GET automatically, so an explicit `head` operation
  conflicts.
* Phase 2 territory: `style` and `explode`, `deepObject`, `multipart/form-data` with `encoding`,
  multi-media-type content negotiation, `discriminator`, `links`, `xml`.

## The support matrix

**Every row is provisional.** `Open` means the discussion has not happened; the other values are
current intent for the first release, not shipped behavior.

### Document

| Construct                               | Level        | Note                                                                                                                                                                                                                                 |
|-----------------------------------------|--------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `openapi` 3.0.x                         | Supported    | Dispatches to the 3.0 [strategy](#handling-30-and-31-the-version-strategy).                                                                                                                                                          |
| `openapi` 3.1.x                         | Supported    | Dispatches to the 3.1 strategy.                                                                                                                                                                                                      |
| Any other version                       | Rejected     | Including 2.x. Convert before adopting.                                                                                                                                                                                              |
| `info`, `externalDocs`                  | Out of scope | Documentation metadata with no routing effect. `info.version` becomes load-bearing only for [breaking-change enforcement](./LIFECYCLE.md#unstable-by-default-and-what-stable-costs-us).                                              |
| `tags`                                  | Partial      | No routing effect, but they are the author's own grouping of their contract, and the package reuses it rather than inventing one — see [scaffolding output](./CODE-GENERATION.md#the-build-names-the-command-instead-of-running-it). |
| `jsonSchemaDialect` (3.1)               | Open         | Only the default dialect is realistically honorable.                                                                                                                                                                                 |
| `servers`                               | Open         | See [still to discuss](#still-to-discuss).                                                                                                                                                                                           |
| `security` (root)                       | Open         |                                                                                                                                                                                                                                      |
| `webhooks` (3.1)                        | Open         |                                                                                                                                                                                                                                      |
| `x-` extensions                         | Out of scope | Preserved by the parser and readable, but the package acts on none of them — except the three it defines itself, on the row beneath.                                                                                                 |
| `x-audience`, `x-lifecycle`, `x-sunset` | Partial      | The extensions this package defines: read and checked by the doctor. The breaking-change enforcement `x-lifecycle` gates is [not phased yet](./ROADMAP.md). Rules: [lifecycle](./LIFECYCLE.md).                                      |

### Paths and operations

| Construct                               | Level     | Note                                                                                                                                                                                                                     |
|-----------------------------------------|-----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `paths`                                 | Supported | The source of every registered route.                                                                                                                                                                                    |
| Path templating `{param}`               | Partial   | Conforming names only, pending the [naming decision](#parameter-names-are-a-naming-contract-not-a-mapping-problem).                                                                                                      |
| Several parameters in one segment       | Open      |                                                                                                                                                                                                                          |
| Path Item `$ref`                        | Open      | Special-cased by the parser (`PathItem.php:73-78`).                                                                                                                                                                      |
| `get`, `post`, `put`, `patch`, `delete` | Supported |                                                                                                                                                                                                                          |
| `options`                               | Open      | Conflicts with Laravel's own handling.                                                                                                                                                                                   |
| `head`                                  | Open      | Laravel derives HEAD from GET automatically.                                                                                                                                                                             |
| `trace`                                 | Rejected  | [Not routable](#trace-cannot-be-routed).                                                                                                                                                                                 |
| Route ordering                          | Supported | [Document order wins](#route-order-the-spec-files-order-is-the-route-order).                                                                                                                                             |
| `operationId`                           | Partial   | Names the generated controller and method. **Required on `public` + `stable` operations**; elsewhere the [method and path](./CODE-GENERATION.md#when-operationid-is-absent-derive-from-method-and-path) stand in for it. |
| `deprecated`                            | Partial   | No effect on routing, but it is the authoritative lifecycle state and it gates the `x-sunset` rule. See [lifecycle](./LIFECYCLE.md).                                                                                     |
| `callbacks`                             | Open      |                                                                                                                                                                                                                          |

### Parameters, bodies, responses

| Construct                                         | Level    | Note                                        |
|---------------------------------------------------|----------|---------------------------------------------|
| `parameters` (`path`)                             | Partial  | Needed for routing. Validation is Phase 2.  |
| `parameters` (`query`, `header`, `cookie`)        | Deferred | No routing effect. Phase 2 for validation.  |
| `style`, `explode`, `allowReserved`, `deepObject` | Open     | Phase 2.                                    |
| `requestBody`                                     | Deferred | Phase 2.                                    |
| `responses`                                       | Deferred | Phase 2, and the input to the Faker mocker. |
| `links`                                           | Open     |                                             |
| Media type `encoding`                             | Open     | Phase 2.                                    |

### Schemas

| Construct                                                                                                                                                     | Level | Note                                                                                                                                                                              |
|---------------------------------------------------------------------------------------------------------------------------------------------------------------|-------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Core JSON Schema subset shared by 3.0 and 3.1                                                                                                                 | Open  | Phase 2 defines how far this goes.                                                                                                                                                |
| `nullable` / `type: [..., "null"]`                                                                                                                            | Open  | Normalized by the strategy; the normal form is not chosen.                                                                                                                        |
| `allOf`, `oneOf`, `anyOf`, `not`                                                                                                                              | Open  |                                                                                                                                                                                   |
| `discriminator`, `xml`                                                                                                                                        | Open  |                                                                                                                                                                                   |
| 3.1-only keywords (`const`, `prefixItems`, `$defs`, `if`/`then`/`else`, `patternProperties`, `dependentSchemas`, `unevaluatedProperties`, `contentMediaType`) | Open  | Blocked on a [parser caveat](#parser-caveats): the parser hands these back as raw arrays with unresolved `$ref`. Whatever we decide, it cannot be "read them from cebe and hope". |

### References and security

| Construct                                                            | Level     | Note                                                                                                                                                                                                                                                  |
|----------------------------------------------------------------------|-----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Local `$ref` within the document                                     | Supported |                                                                                                                                                                                                                                                       |
| `$ref` to another local file                                         | Supported | Multi-file specs are a Phase 1 goal.                                                                                                                                                                                                                  |
| Remote `$ref` by URL                                                 | Partial   | [Allowlisted domains only](./REMOTE-REFERENCES.md); anything else is an error.                                                                                                                                                                        |
| Recursive schema (`$ref` back to an ancestor)                        | Supported | A self-referential schema — a tree, a comment thread, nested categories — resolves. Verified: under `RESOLVE_MODE_ALL` the parser walks it on demand without limit or error; under `RESOLVE_MODE_INLINE` the inner `$ref` stays a `Reference` object. |
| Pure `$ref` cycle (`A` → `B` → `A`)                                  | Rejected  | A reference chain pointing only at other references and looping back. **The parser does not fail gracefully here** — see [parser caveats](#parser-caveats). The doctor must catch it before the parser is handed the document.                        |
| `$dynamicRef`, `$dynamicAnchor`, `$recursiveRef`, `$recursiveAnchor` | Rejected  | JSON Schema 2019-09/2020-12 dynamic-scope resolution, reachable only in 3.1. A different feature from a recursive schema, and rare outside meta-schemas. Rejected with a message that says which of the two you probably meant.                       |
| `securitySchemes` and `security`                                     | Open      | See [still to discuss](#still-to-discuss).                                                                                                                                                                                                            |

## Changing this document

The rules in [`DOCUMENTATION.md`](./DOCUMENTATION.md) apply, plus two specific to this file:

* **A row changes in the same commit as the behavior it describes.** This matrix is the definition of
  done for any change to what the package accepts from a spec. A behavior change that leaves the
  matrix stale is an incomplete change, exactly as
  [`AGENTS.md`](../AGENTS.md#every-change-lands-in-three-places) states.
* **State the release impact when moving a row.** Rule 4 makes direction meaningful: say whether the
  move is minor or major, in the commit message.
