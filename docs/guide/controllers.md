---
title: Controllers
audience: Users
covers: >
    Why there is no grouped or invokable controller, `BasicSpecController` and `ModelSpecController` and which one an
    operation gets, why `getModelClass()` is `final` on the generated file rather than on the package's own class, the
    `getQuery()` extension point and how it composes with pagination, why mass-assignment write defaults have no
    opt-out, the doctor check that validates a model against what the specification actually sends it, and scaffolding
    one controller at a time.
read_before: >
    Implementing anything that turns an operation into a controller, or touching what `spec:make` scaffolds.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Controllers

An operation needs something to answer it. This document owns what that something is: how many files it takes, what it
assumes about your application, and where a developer's own code attaches to it.

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

## One controller per operation, and nothing grouped

**Decision: every operation gets its own generated controller, with one method named after the operation.** Not a
resource controller carrying several operations, and not an invokable class.

Both alternatives were considered and both fail for reasons specific to this package rather than to controllers in
general:

- **Grouping breaks incremental implementation.** A controller generated with five abstract methods needs a concrete
  subclass implementing all five before PHP will instantiate it — you cannot ship three today and two tomorrow. The
  candidate for grouping was `x-controller` naming which operations share a class; it was dropped for exactly this
  reason, on top of introducing a second naming key that [identity](#identity-is-the-path-and-the-method-not-the-name)
  in [`code-generation.md`](./code-generation.md) does not model.
- **An invokable breaks the one string that makes this codebase greppable.** A method named after the operation means
  `grep showUser` finds the specification and the code in one search. An invokable's class name carries that information
  instead, transformed through whatever naming convention was chosen — a second spelling of one fact, and the exact
  duplication [`operationId`-derived naming](./code-generation.md#naming-and-the-rename-problem) already guards against.
  It also does more damage than the DRY argument suggests: it is precisely the kind of gap an AI agent, reading a
  handful of files rather than a codebase, has no way to close by inference — which is a first-class concern for this
  package, stated in [the docblock norm](./code-generation.md#every-generated-file-explains-itself).

The
[override mechanism is the one factories already use](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares):
a human subclass is found by what it `extends`, in a configured directory, and it becomes the route's target instead of
the generated default.

## Two base controllers, and the specification picks one

**The specification knows nothing about your database, and that is correct — not a gap to route around.** An operation
can be an arbitrary action: a trigger, a computed report, a webhook receiver. Assuming every operation maps to an
Eloquent model would be this package inventing a fact the contract never stated, which
[rule 3](./openapi-support.md#the-four-rules) does not license.

**Decision: two base controllers ship with the package, named `BasicSpecController` and `ModelSpecController`, and an
operation's own specification decides which one its generated controller extends** — not a config key, not a flag on
`spec:make`. Settled now, ahead of everything else here, so the rest of this document — and everything that will link
into it — has a name to use rather than a placeholder.

| Base controller       | Knows about                              | Default behavior                                                                                 |
| --------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `BasicSpecController` | Nothing — no model, no query, no ORM     | [`501`](./code-generation.md#an-unimplemented-operation-answers-501), exactly as already decided |
| `ModelSpecController` | An Eloquent model, via `getModelClass()` | Built on top of it: a default query, and defaults for reading, creating, updating and deleting   |

**An operation opts into `ModelSpecController` by declaring `x-model`. Without it, `BasicSpecController` — and its
default stays `501`, unchanged from what
[code-generation.md already decided](./code-generation.md#an-unimplemented-operation-answers-501).** No new extension
called it out; declaring `x-model` on an operation _is_ the extension point, in the same spirit as
[`x-audience` and `x-lifecycle`](./lifecycle.md) declaring a stronger claim by being present rather than by a separate
switch.

## `getModelClass()` is `final`, but not where you would first guess

`ModelSpecController` is one class, shared by every model-driven operation — a `User` operation and an `Order` operation
both extend it. **Its own `getModelClass()` has to be `abstract`, because the package cannot know a value that differs
per operation.**

**Decision: the generated controller — one per operation — implements `getModelClass()` as `final`, with `x-model`'s
value baked in at build time.** `final` on the generated file, not on the package's class, is what makes the distinction
between "which model" and "how it's queried" enforceable by PHP rather than by a convention someone has to remember:

- **`getModelClass()`: closed.** Once `x-model` names a model, no subclass may name a different one. This is what
  removes the two-sources-of-truth risk structurally: there is exactly one place `x-model` is read into code, and
  nothing downstream can disagree with it.
- **`getQuery()`: open.** `ModelSpecController`'s default is `($this->getModelClass())::query()`. A human subclass
  overrides it freely — a global scope, eager loading, hiding soft-deleted rows — because query construction is
  legitimate business customization that never contradicts what the specification declared the model to be.

The same reasoning
[already used for factory overrides](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares)
decides who wins if `getQuery()` is overridden twice: exactly one `extends` per generated controller, found by the same
configured-directory scan, or a build-time error naming both classes.

### Reads, and where they stop needing a line of code

A single-resource read needs no `x-model` at all, and needs no override. Laravel's own implicit route-model binding
resolves it: **the generated method's parameter is type-hinted with the model class**, a build-time decision exactly
like
[deriving a method name from the operation's identity](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path),
and Laravel does the actual binding at request time with no package code involved at all — consistent with
[the runtime never seeing the spec](./code-generation.md#the-runtime-never-sees-the-spec). The type hint still comes
from `x-model`, so a single value in the specification feeds three things: this type hint, `getModelClass()`'s `final`
return, and `getQuery()`'s default source. One fact, three consumers, never two answers.

A collection read has no bound instance to type-hint, which is the one case
[rule 3](./openapi-support.md#the-four-rules) genuinely needs `x-model` to say anything at all: the default becomes
`$this->getQuery()`, handed to [pagination's built-in driver](./pagination.md#one-built-in-driver) when the operation's
response is paginated.

**Decision: this package ships no filtering or sorting.** OpenAPI has no vocabulary for a filter DSL any more than it
does for [row-level authorization](./security.md#past-the-scope-check-it-is-a-policys-job), and the same test applies:
inventing one would be a far larger commitment than anything else in this document set, for a feature every serious
Laravel project already reaches for a dedicated package to solve. `getQuery()` is already the hook — a project that
needs filtering overrides it and wires in Spatie's Query Builder, Scout, or whatever it already uses. Nothing new to
configure, nothing this package has an opinion about.

### Writes, by the same default

**Decision: `create`, `update` and `delete` get the same treatment as reads** — a default built from `getModelClass()`
and the request's already-validated data, and a subclass free to override it for anything more than plain mass
assignment:

| Operation shape | Default                                               |
| --------------- | ----------------------------------------------------- |
| Create          | `($this->getModelClass())::create($validated)`        |
| Update          | `$model->update($validated)`, `$model` bound as above |
| Delete          | `$model->delete()`, `$model` bound as above           |

`$validated` is what the [generated `FormRequest`](../project/roadmap.md) already produced from the operation's request
body schema — nothing new reads the specification a second time.

**This is where the doctor earns its keep.** Mass assignment silently drops whatever a model's `$fillable` (or
`$guarded`) does not allow — Eloquent does not raise for it. A request body schema declaring a field the model will not
accept is therefore invisible at the wire and only ever noticed as "why didn't this save," far from its cause.
**Decision: the doctor compares a model-aware operation's validated fields against the bound model's mass-assignment
rules**, and reports the mismatch by name — the same shape as
[the security scheme naming contract](./security.md#scheme-names-are-a-naming-contract-with-your-guards): a relationship
that has to hold between two files the specification cannot itself see across.

Anything past plain mass assignment — charging a payment, dispatching a job, enforcing an invariant the schema cannot
express — is an ordinary override of the generated method. Nothing separate to learn: the controller **is** the
extension point, on either base, which is what removes the need for a distinct "handler" concept altogether.

**Decision: no opt-out, and no way to force `501` on a `create`/`update`/`delete` `x-model` already gates.** The
tempting worry is a `POST /orders` that saves the row and returns `200` while nothing actually charged the card. That is
not a defect in this default — it is the boundary this whole package has been honest about from the start. **Neither the
specification nor this package is magic.** They own how a client talks to the application over HTTP; a developer owns
what happens once a request arrives, exactly as [`security.md`](./security.md#past-the-scope-check-it-is-a-policys-job)
already draws that same line for authorization. `x-model` on an operation is a declaration that plain mass assignment is
an acceptable starting point for it — the specification did its job the moment the contract is honored on the wire. A
flag to suppress the default would only protect a developer who shipped `x-model: Order` on a payment-charging endpoint
without ever opening the generated file, and building for that case would mean designing around the wrong audience
rather than trusting the one this package is for.

## Composable capabilities, chosen the same way

**Open:** how pagination and future model-aware capabilities attach. The leading shape is a trait per capability, mixed
into the generated controller alongside the base it extends — chosen automatically, the same way the base itself is: an
operation whose response the doctor already recognizes as [paginated](./pagination.md) gets the pagination trait; one
that does not, does not. Nothing configured by hand, and the generated file's own
[docblock](./code-generation.md#every-generated-file-explains-itself) names exactly which traits and which base it
received and why — a reader should never have to reconstruct that decision from the class declaration alone.

## Scaffolding one controller at a time

**Decision: `spec:make` names one operation and scaffolds one controller for it** — the atomic case, and the one every
other form of the command is sugar over. `spec:make --tag=Users` and `--all` still exist as
[bulk convenience](./code-generation.md#the-build-names-the-command-instead-of-running-it), but each still creates one
file per operation, extending whichever base
[that operation's own specification selected](#two-base-controllers-and-the-specification-picks-one) — never a shared,
grouped file. Bulk is a loop over the singular command, not a second mechanism, and the generated controller's docblock
says which base and which capabilities it received regardless of which form of `spec:make` created it.

## Open questions

- The exact trait or interface shape for
  [pagination and whatever capability follows it](#composable-capabilities-chosen-the-same-way) — sketched above, not
  decided. This is an implementation detail to settle when that capability is actually built, not a design question to
  resolve in the abstract now.

## The doctor counts two things, not three

**Decision: the coverage report separates `501` from everything that works, and stops there.**

```
12 operations answer 501
8 operations work
```

`501` is a real gap: nothing answers the request. Everything else is not a gap, whether it is running on
`ModelSpecController`'s generated default or on a human override — **the doctor exists to find failures, not to narrate
every file that is fine.** Whether a working operation is a default or an override is already answered by that file's
own [docblock](./code-generation.md#every-generated-file-explains-itself), the moment anyone actually opens it.
Repeating that distinction in the coverage report would be the doctor auditing something the code already says about
itself, which is not what [rule 2](./openapi-support.md#the-four-rules) asks for: it asks that a gap be loud, not that
every working file be annotated twice.
