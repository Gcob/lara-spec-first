---
title: Response DTOs
audience: Users
covers: >
    How a response schema becomes a PHP type, why a DTO is `final readonly` and therefore has no abstract layer to
    extend, why hydration lives in a generated factory instead, how a project overrides one of those factories by
    extending it from a directory it declares, why exactly one override per factory is allowed, and what a factory's
    docblock has to carry that no other generated file does.
read_before: >
    Implementing the DTO emitter, the factory emitter, or the scan that finds an override.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Response DTOs

> **TL;DR**
>
> - **Not built yet.** No DTO and no factory is emitted today; this is the design Phase 2 will follow.
> - The shape is generated and not yours; the behavior is yours. That tension is the whole design.
> - A DTO is `final readonly`, so there is no abstract layer to extend. Hydration lives one level removed, in a factory.
> - A project overrides a factory by extending it from a directory the project declares, and the generated default never
>   disappears.
> - Two classes extending one generated factory is a hard error, not a silent pick.

A response schema describes a shape, and an application has to build that shape out of whatever it actually holds. This
file owns the split that follows: the type the build owns entirely, and the class beside it that a project is meant to
teach.

> **None of this is behaviour yet.** The build emits no DTO and no factory today, and no configuration key exists to
> point the override scan anywhere. What is written here is the design [Phase 2](../../project/roadmap.md) will follow.
> Items marked `Open` are undecided.

The DTOs are how a response schema becomes a PHP type. Two properties, and the tension between them is the design:

- **The shape is generated, and not yours.** Properties, types and nullability come from the response schema. A
  hand-edited shape is drift from the contract by definition, and it is exactly what Spec-First exists to prevent.
- **The behavior is yours.** Hydration is where real applications differ, and a generated DTO you cannot teach to build
  itself from your model is a generated DTO people will wrap or abandon.

**Decision: a DTO is `final readonly`.** It is a value object mirroring a piece of the contract, not a class with
behavior of its own to grow — the same reasoning that makes
[`Operation`](https://github.com/Gcob/lara-spec-first/blob/main/src/Contract/Operation.php) and its neighbors
`final readonly` in this package's own types. Which rules out the [two-layer split](./index.md#two-layers) that
customization elsewhere in this subject relies on: there is no abstract DTO to extend, because there is no DTO to
extend, full stop.

### Factories, not subclasses, are where behavior lives

**A note on the name, before anything else.** This "factory" is the design pattern — a class whose one job is
constructing another object — not Laravel's own model factories, which generate fake data for tests and carry
`HasFactory` and `Factory::class` with them. The two share a word and nothing else. Nothing here touches, extends, or
competes with `Illuminate\Database\Eloquent\Factories`.

Hydration therefore cannot live on the DTO itself. It lives one level removed, in a **factory** — an ordinary class, not
final, whose only job is turning a source (a model, an array, whatever the response needs) into the DTO.

**Decision: the build generates one factory per DTO**, mapping by naming convention — the same nomenclature-driven
matching already used
[when `operationId` is absent](./generated-file-anatomy.md#when-operationid-is-absent-derive-from-method-and-path) —
with a default implementation that covers the ordinary case: properties that already exist on the source, under the same
name. This is what makes the other ninety-six DTOs in a hundred-DTO contract need nothing from a developer at all.

### Overriding a factory: extend it, in a directory the project declares

Most response shapes need nothing beyond the default mapping. The few that do should not cost the other ninety-six.
**Decision: a project overrides a factory by writing a class that `extends` the generated one** — no fixed name, no
fixed file, and no service provider to touch.

**The generated factory never disappears, even once overridden.** It is not replaced, it is extended — the override
would have nothing to inherit from otherwise, and a developer would be starting from an empty file instead of a working
default mapping they only need to adjust in part. Every generated factory therefore exists for every DTO, always,
whether or not a project has ever looked at it.

The build finds the override itself, by scanning a **configured set of directories — not the whole project** — for a
class extending each generated factory, and wiring whichever it finds in place of the generated default. The directories
are named in configuration, the same shape as [`remote_references.allowed_hosts`](../remote-references.md): empty by
default, and nothing is scanned until a project says where to look. Scanning the whole application would mean touching
every autoloaded class, vendored packages included, on every build, for a feature four DTOs out of a hundred will ever
use — the cost has to be bounded by what the project actually declares, not by how large `vendor/` happens to be.

**Exactly one override per factory.** Extending a generated factory twice is not a project needing two behaviors from
one thing, it is two behaviors with no rule for which wins. The build refuses to guess: finding two classes that extend
the same generated factory is a hard error, naming both offending classes and the factory they both claim, not a silent
pick of whichever the classmap happened to load first.

This is detection **at build time**, deliberately, not a runtime `class_exists()` check scattered across every place a
DTO gets built — the same reasoning as [everywhere else in this subject](./index.md#the-runtime-never-sees-the-spec):
explicit over dynamic, and the cost paid once rather than on every request. The consequence to state plainly: an
override added without rerunning the build has not taken effect yet — a case for [drift](../doctor.md#what-it-checks),
not a new failure mode.

**Open:** the config key's name, and whether it recurses into subdirectories by default; the exact mechanism for finding
the `extends` relationship — reflection over the classes the configured directories autoload is the leading answer,
rather than a token scan over spellings, since an `extends` clause needs the language's own resolution of `use` imports
and aliases to be trustworthy, not a match on spelling; and the name of the exception thrown when two classes claim one
factory.

### What a factory's docblock carries

Factories follow the norm
[every generated file follows](./generated-file-anatomy.md#every-generated-file-explains-itself); what is specific to
them is what counts as a finding worth reporting. **The mapping's own result:** which properties matched the source by
name, which did not, and anything a reader should check before trusting the default — because a factory that silently
skipped a property is the one thing a reader cannot see by looking at it.

Its navigation line is the general rule applied to
[the override scan](#overriding-a-factory-extend-it-in-a-directory-the-project-declares): the `spec:make`
[flag](./scaffolding.md#per-type-flags-belong-here) that scaffolds an override when none was found, replaced by `@see`
at the detected class when one was. Since the generated factory
[stays in place even when overridden](#overriding-a-factory-extend-it-in-a-directory-the-project-declares), that
annotation is the only thing distinguishing the default a reader is looking at from the behavior that actually runs.

`spatie/laravel-data` remains a candidate for the generated shape itself — its casting, validation and serialization are
useful independently of who builds the object — but its own `from()`-override ergonomics are no longer the fit they once
were: a `Data` object is not `final`, and this design deliberately does not lean on DTO-level inheritance for
customization. **Whether we depend on it or only take the shape is undecided** and belongs in
[`stack.md`](../../project/stack.md) once settled — a dependency buys casting, validation and serialization for free, at
the cost of binding generated code to another package's API and release cycle.

## What the DTOs deliberately do not cover

Three things a reader could reasonably expect here and will not find, each because it belongs to something else:

- **The request side.** One `FormRequest` per operation, derived from the request body and parameter schemas, is
  [its own Phase 2 item](../../project/roadmap.md) and lands before this one, since it is what supplies `$validated` to
  everything downstream. Nothing on this page describes input.
- **Checking a response against its schema at run time.** A DTO is generated from the schema, so the shape is guaranteed
  by the build rather than verified per request —
  [the runtime never opens a specification](./index.md#the-runtime-never-sees-the-spec), and a response that has drifted
  from the contract is [the drift check](../doctor.md#what-it-checks)'s finding, not a DTO's exception.
- **The types a frontend consumes.** They come from `openapi-typescript` run over the published document, which is
  [`publishing.md`](./publishing.md#the-frontend-gets-its-types-from-openapi-typescript-not-from-this-build)'s subject.
