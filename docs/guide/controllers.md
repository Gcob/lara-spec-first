---
title: Controllers
audience: Users
covers: >
    Why there is no grouped or invokable controller, why the package ships one controller and composes behavior through
    a context object instead of an inheritance chain, `DefaultContext`, `ModelContext` and `ModelCollectionContext` and
    how an operation's specification picks one, why `context()` is `final` on the generated file, how a single-item and
    a collection response are told apart from the schema, why mass-assignment write defaults have no opt-out, the doctor
    check that validates a model against what the specification actually sends it, and scaffolding one controller at a
    time.
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

## Composition, not a controller inheritance chain

An earlier draft of this design shipped two base controllers — one bare, one model-aware — with a generated controller
extending whichever applied and a human subclass extending that. It was wrong for a reason worth stating plainly: **it
grew a controller inheritance chain four and five levels deep**, human subclass extending generated class extending a
model-aware base extending a bare base. Every method's actual behavior became a question of which ancestor defines it,
which is precisely the fragile-base-class problem, and precisely the kind of gap an AI agent reading one file cannot
resolve without reading four.

It was also the odd one out. Nothing else in this document set customizes behavior by extending a stack of package
classes: a factory is
[an object the controller calls](./code-generation.md#factories-not-subclasses-are-where-behavior-lives), not something
it descends from, and a [driver](./drivers.md) is the same shape again. The controller design was the only place still
reaching for inheritance to express "which behavior applies here."

**Decision: the package ships one controller, `SpecController`, and composes behavior through a context object
instead.** `SpecController` is deliberately thin — a handful of methods, each delegating to `$this->context()` — and
every operation's generated controller extends it, full stop. There is no second or third controller to choose between.

```php
// Generated, one per operation.
final class ShowUserController extends SpecController
{
    final protected function context(): ModelContext
    {
        return new ModelContext(User::class);
    }

    public function routeAction(User $user): UserDto
    {
        $this->context()->bind($user);

        return $this->getResponse();
    }
}
```

`getQuery()` and `getResponse()` live on `SpecController`, each with a default body that delegates to
`$this->context()`, and each freely overridable in a human subclass — overriding one no longer means understanding which
ancestor among several currently provides it, because there is exactly one ancestor, and it does exactly one thing: ask
the context.

## `DefaultContext`, `ModelContext`, `ModelCollectionContext`

**Decision: three context classes ship with the package, plain PHP objects with no HTTP concerns of their own**, and an
operation's own specification decides which one its generated controller's `context()` constructs:

| Context                  | Knows about                              | Behavior                                                                                                                                                                                         |
| ------------------------ | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `DefaultContext`         | Nothing                                  | Throws on every call — rendered as [`501`](./code-generation.md#an-unimplemented-operation-answers-501), the existing decision, now carried by one exception rather than a special-cased handler |
| `ModelContext`           | One Eloquent model, given its class name | A default query and single-item response, plus create/update/delete defaults                                                                                                                     |
| `ModelCollectionContext` | The same, for a collection               | A default query and collection response, composing with pagination                                                                                                                               |

An operation opts in by declaring `x-model`; without it, `context()` is left unoverridden and `SpecController`'s own
default returns `DefaultContext` — **throwing loudly is the point.** A silent no-op context was considered and rejected:
[rule 2](./openapi-support.md#the-four-rules) treats silence as the failure mode to avoid everywhere else in this
package, and a context that quietly did nothing would be exactly that, dressed up as a design pattern.

**`context()` is `final` on the generated file, not on `SpecController`.** `SpecController` cannot declare it `final`
itself, because the method's return type — and the model class baked inside it — differs per operation. Declaring it
`final` on the one-per-operation generated file is what makes "which model, and whether it is a collection" closed to
override, enforced by PHP rather than by a convention someone has to remember — the same guarantee an earlier draft of
this design got from a `final getModelClass()`, relocated onto the context rather than lost.

### Telling a single item from a collection

**Decision: the response schema decides, and the rule reuses [pagination's own mapping](./pagination.md), not a
hardcoded property name.** A response schema of `type: array` is a bare list — `ModelCollectionContext`, no envelope. A
response schema of `type: object` whose [configured `mapping.collection` key](./pagination.md#one-built-in-driver) names
an array property is an enveloped, paginated list — `ModelCollectionContext` again, this time composed with the
pagination driver. Anything else is a single item — `ModelContext`.

Hardcoding the envelope property to `data` would be wrong the moment a project's own pagination mapping names it
something else — exactly the two-sources-of-truth failure this document keeps naming and avoiding elsewhere. There is
one place a project says what its envelope's collection property is called, and this reuses it rather than assuming one.

## Reads, and where they stop needing a line of code

A single-resource read needs no `x-model` at all, and needs no override. Laravel's own implicit route-model binding
resolves it: **the generated method's parameter is type-hinted with the model class**, a build-time decision exactly
like
[deriving a method name from the operation's identity](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path),
and Laravel does the actual binding at request time with no package code involved at all — consistent with
[the runtime never seeing the spec](./code-generation.md#the-runtime-never-sees-the-spec). The type hint still comes
from `x-model`, so a single value in the specification feeds three things: this type hint, `ModelContext`'s constructor
argument, and its default query's source. One fact, three consumers, never two answers.

A collection read has no bound instance to hand the context, which is the one case
[rule 3](./openapi-support.md#the-four-rules) genuinely needs `x-model` to say anything at all: the default becomes
`$this->getQuery()`, handed to [pagination's built-in driver](./pagination.md#one-built-in-driver) when the operation
declares one.

**Decision: this package ships no filtering or sorting.** OpenAPI has no vocabulary for a filter DSL any more than it
does for [row-level authorization](./security.md#past-the-scope-check-it-is-a-policys-job), and the same test applies:
inventing one would be a far larger commitment than anything else in this document set, for a feature every serious
Laravel project already reaches for a dedicated package to solve. `getQuery()` is already the hook — a project that
needs filtering overrides it and wires in Spatie's Query Builder, Scout, or whatever it already uses. Nothing new to
configure, nothing this package has an opinion about.

## Writes, by the same default

**Decision: `create`, `update` and `delete` get the same treatment as reads** — a default `ModelContext` builds from its
model class and the request's already-validated data, and a human subclass free to override the generated method for
anything more than plain mass assignment:

| Operation shape | Default                                               |
| --------------- | ----------------------------------------------------- |
| Create          | `($model)::create($validated)`                        |
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
extension point, which is what removes the need for a distinct "handler" concept altogether.

```php
public function createOrder(): OrderDto
{
    app(PaymentService::class)->makePayment(...);

    return $this->getResponse();
}
```

**Decision: no opt-out, and no way to force `501` on a write `x-model` already gates.** The tempting worry is a
`POST /orders` that saves the row and returns `200` while nothing actually charged the card. That is not a defect in
this default — it is the boundary this whole package has been honest about from the start. **Neither the specification
nor this package is magic.** They own how a client talks to the application over HTTP; a developer owns what happens
once a request arrives, exactly as [`security.md`](./security.md#past-the-scope-check-it-is-a-policys-job) already draws
that same line for authorization. `x-model` on an operation is a declaration that plain mass assignment is an acceptable
starting point for it — the specification did its job the moment the contract is honored on the wire. A flag to suppress
the default would only protect a developer who shipped `x-model: Order` on a payment-charging endpoint without ever
opening the generated file, and building for that case would mean designing around the wrong audience rather than
trusting the one this package is for.

## Composable capabilities, chosen the same way

**Open:** how pagination and future capabilities attach to a context. Composition makes this easier than the
inheritance-chain design would have: a capability can decorate or compose into `ModelCollectionContext` as a plain PHP
object, with no HTTP or routing concerns to thread through it. Chosen automatically either way — an operation whose
response the doctor already recognizes as [paginated](./pagination.md) gets it, one that does not, does not — and the
generated file's own [docblock](./code-generation.md#every-generated-file-explains-itself) names exactly which context
and which capabilities it received and why. A reader should never have to reconstruct that decision from the class
declaration alone.

## Scaffolding one controller at a time

**Decision: `spec:make` names one operation and scaffolds one controller for it** — the atomic case, and the one every
other form of the command is sugar over. `spec:make --tag=Users` and `--all` still exist as
[bulk convenience](./code-generation.md#the-build-names-the-command-instead-of-running-it), but each still creates one
file per operation, each extending `SpecController` and constructing whichever context
[that operation's own specification selected](#defaultcontext-modelcontext-modelcollectioncontext) — never a shared,
grouped file. Bulk is a loop over the singular command, not a second mechanism, and the generated controller's docblock
says which context and which capabilities it received regardless of which form of `spec:make` created it.

## Open questions

- The exact trait, decorator or interface shape for
  [pagination and whatever capability follows it](#composable-capabilities-chosen-the-same-way) — sketched above, not
  decided. This is an implementation detail to settle when that capability is actually built, not a design question to
  resolve in the abstract now.
- The exact namespace for `SpecController` and the context classes — a detail to settle when the package's own namespace
  layout is decided, not a design question.
- Whether `ModelCollectionContext` extends `ModelContext` to reuse its model-class storage, or composes it — an
  implementation detail with no bearing on anything a consumer sees.

## The doctor counts two things, not three

**Decision: the coverage report separates `501` from everything that works, and stops there.**

```
12 operations answer 501
8 operations work
```

`501` is a real gap: nothing answers the request. Everything else is not a gap, whether it is running on
`ModelContext`'s generated default or on a human override — **the doctor exists to find failures, not to narrate every
file that is fine.** Whether a working operation is a default or an override is already answered by that file's own
[docblock](./code-generation.md#every-generated-file-explains-itself), the moment anyone actually opens it. Repeating
that distinction in the coverage report would be the doctor auditing something the code already says about itself, which
is not what [rule 2](./openapi-support.md#the-four-rules) asks for: it asks that a gap be loud, not that every working
file be annotated twice.
