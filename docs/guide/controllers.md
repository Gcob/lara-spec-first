---
title: Controllers
audience: Users
covers: >
    Why an operation gets one controller with one `routeAction` method rather than a grouped or invokable class, how
    `x-controller` names a customizable operation's class and why that name is what makes the import stable, why an
    operation without it is `final`, why `spec:make` is the only way in and what it may write into the specification,
    what the generated controller contains and why `routeAction` is one line calling a named CRUD method, why `x-model`
    is the switch for everything model-shaped and what an operation still gets without it, why the model contract is an
    interface while the CRUD semantics are empty markers, how the build detects that semantic, why mass-assignment write
    defaults have no opt-out, and how middleware attaches.
read_before: >
    Implementing anything that turns an operation into a controller, or touching what `spec:make` scaffolds.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Controllers

An operation needs something to answer it. This document owns what that something is: how many files it takes, what it
assumes about your application, and where a developer's own code attaches to it.

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

## One controller per operation, one method named `routeAction`

**Decision: every operation gets its own generated controller, carrying exactly one route method, always named
`routeAction`.** Not a resource controller holding several operations, and not an invokable class.

**One fixed method name rather than one derived from the operation**, and the reasoning is predictability: every
controller this package generates has `routeAction`, so "which method do I override" has one answer, forever, with no
naming convention to learn and no derived spelling to reconstruct. The operation's identity is carried by the class name
and stated exactly in [the file's own docblock](./code-generation.md#every-generated-file-explains-itself), which is
where a reader looks anyway.

Two alternatives were considered and dropped:

- **Grouping several operations into one controller.** A controller generated with five abstract methods needs a
  concrete subclass implementing all five before PHP will instantiate it — you cannot ship three today and two tomorrow.
  One operation per class removes the problem entirely rather than managing it. Grouping is also what `x-controller` was
  first considered for; [it does something else here](#the-specification-decides-what-is-customizable), and nothing in
  this package produces a shared file.
- **An invokable.** `routeAction` appears in the generated route registration, in stack traces, in IDE navigation, and
  in a grep for every spec-driven action at once. `__invoke` appears in none of them: it is a magic method whose name
  says nothing about what it does, and the route registration degrades from an explicit
  `[Controller::class, 'routeAction']` pair to a bare class string.

## The specification decides what is customizable

**It follows from the package's own name.** `lara-spec-first` means the contract leads and PHP follows, so **whether an
operation has custom code is a fact the specification states**, not a convention this package goes looking for in the
filesystem. Any other answer would put the code in charge of something about the contract, which
[`AGENTS.md`](../../AGENTS.md) calls going the wrong way, and which is the one direction this project does not travel.

That reasoning is worth writing down rather than assuming, because the alternatives all look reasonable in isolation and
every one of them inverts the direction: an attribute on the developer's class, a scan for whatever extends a generated
parent, a config key listing customized operations. Each puts the answer in PHP. Only the specification saying so keeps
the direction intact.

**Decision: `x-controller` on an operation carries the fully-qualified name of its custom controller**, the same way PHP
itself writes a class reference. It is not a grouping key and it does not name a base class to extend — it names _this
operation's_ controller, one operation to one class, and its only job is to be that name.

```yaml
x-controller: App\Http\Controllers\UserController
```

A full name rather than one relative to a configured root, because an FQN needs no second setting to resolve and cannot
be read two ways. There is nothing to concatenate, so there is nothing to get wrong.

| The operation declares | Generated class name                                                           | Extendable  |
| ---------------------- | ------------------------------------------------------------------------------ | ----------- |
| `x-controller`         | Derived from `x-controller`                                                    | Yes         |
| Nothing                | Derived from `operationId`, or from the normalized method and path when absent | No, `final` |

**That table is the whole design, and the reason is one property: the extendable class's name comes from a value that
exists only to name it.** `x-controller` has no other meaning in the contract, so nothing else can move it. An
`operationId` can be renamed, or added to an operation that never had one — and the doctor
[actively encourages adding one](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path), which
under any name-from-`operationId` scheme means the package nudges you toward breaking your own imports. Deriving the
extendable name from `x-controller` instead means **the only thing that can break an import is the developer changing
the name they chose themselves**, which is the deliberate versioning decision this package already says an identifier
change is.

**And `final` is what makes that guarantee real rather than advisory.** An operation with no `x-controller` has a
derived name, the documentation has always called derived names disposable, and `final` stops anyone from building an
`extends` on top of something disposable. Declaring `x-controller` is how a project says "this name is mine now, I
intend to depend on it."

The same rule generalizes past controllers: **anything whose generated name is derived rather than declared is
`final`.** A [DTO factory](./code-generation.md#factories-not-subclasses-are-where-behavior-lives) named from a
`components/schemas` entry is extendable because that name was chosen; one named from an inline, anonymous response
schema is not.

### Two classes, found by name rather than by a scan

A customizable operation therefore has two controllers: the generated parent, rewritten on every build, and the custom
child, created once. **The build does not scan for the child — the specification already told it the name**, so
resolution is a lookup rather than a search. That is simpler than
[the factory override mechanism](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares),
which has to scan configured directories precisely because nothing in the specification names a factory override.

The route points at the child when it exists, and at the generated parent when it does not — resolved at build time, so
the registration stays a serializable pair of strings and `route:cache` keeps working.

**The generated parent takes the same short name, inside
[the generated namespace](./code-generation.md#where-generated-code-lives), and the child extends it by fully-qualified
name.** No `use`, no alias, no suffix:

```php
namespace App\Http\Controllers;

class UserController extends \App\Http\Generated\Controllers\UserController
{
    // override what you need.
}
```

An inline FQN in the `extends` clause is ordinary PHP and it removes the whole problem the alternatives created. A
suffix on the generated side would mean inventing a naming convention nobody asked for; importing a same-named parent
would force `use …\Generated\UserController as GeneratedUserController` into every custom file a developer owns. Writing
the name where it is used costs one line and reads as exactly what it is: this class is the generated version of me.

**Two `x-controller` values that reduce to the same generated parent are a build error, naming both.** Distinct FQNs can
still share a short name — `…\Admin\UserController` and `…\Api\UserController` — and silently letting one generated
parent serve two operations is the kind of guess this package refuses everywhere else.

### `spec:make` is the only way in

**Decision: a custom controller is created by `spec:make` and never by the build**, which is
[the invariant](./code-generation.md#the-invariant-a-build-never-destroys-human-work) rather than a new rule. It
scaffolds one file for one operation, extending that operation's generated parent.

**Decision: `spec:make` prints the extension to add, names the exact line, and offers to insert it — defaulting to no.**
Wanting a custom controller and having to hand-edit YAML first is friction with no purpose, but the specification is the
source of truth and nothing writes to it without being asked:

```
getUser has no x-controller, so its generated controller is final and cannot be extended.

Add to openapi.yaml, line 395:

    x-controller: App\Http\Controllers\UserController
    x-model: App\Models\User

Insert it there now? [y/N]
```

> Note that a flag to force insert the row will be considered.

Answering no leaves a copyable block and the exact line, which is
[the pattern this package already uses](./code-generation.md#the-build-names-the-command-instead-of-running-it) when a
human decision is required. Answering yes runs the insertion below.

**The insertion never round-trips the document through a YAML dumper.** Parsing and re-emitting destroys comments, key
order and anchors, and this is the one file read in every pull request. YAML's indentation is predictable enough that
there is nothing to parse for: the operation's line is known, and one line goes in beneath it at the matching depth. No
library, no reformat, no reflow.

**And the edit verifies itself before it lands.** The insertion happens on a temporary copy, the copy is read back
through [the normal reading pipeline](./openapi-support.md#reading-a-document), and the resulting operations are
compared against the ones the original produced. Everything must be identical except the extension that was just added.
Only then does the temporary copy replace the original.

**If that comparison fails, nothing is written.** The command stops, says the automatic insertion would have changed
something it did not intend, and falls back to printing the block for a human to place. That path should never run,
which is exactly why it must exist: an automatic edit to the source of truth is worth a check that cannot be argued
with.

**And it refuses outright on a document the project does not own.** An operation reached through a
[vendored remote reference](./remote-references.md) lives in a file the next fetch overwrites, so an insertion there
would silently disappear. That has a broader answer behind it worth saying plainly: **a specification you do not control
is the consumer's problem, not this package's.** Building an application on a contract someone else can change under you
is already a risk this package cannot design away, and bending the whole customization model around it would cost every
other project clarity. Vendor the reference, or own a copy.

## What the generated controller contains

Inheritance carries exactly one thing here — the two-class parent-child seam above — and nothing else. `SpecController`
is one abstract base, shared by every operation, and it stays deliberately thin: no per-operation knowledge, because it
cannot have any.

**Everything an operation needs is generated into its own class, narrowly typed.** An earlier draft of this design put
that knowledge in context objects the controller composed — a `ModelContext` holding the model, the query, the response
and the write defaults. Those are gone, because once the generated method calls the DTO factory directly there is
nothing left for a context to hold, and a delegation into an object graph reads worse than the explicit code it was
hiding.

**Decision: `routeAction` is one line, calling a named CRUD method the build chose.**

```php
// Generated. `final` here because this operation declares no x-controller.
final class GetUsersIdController extends SpecController implements ShowsResource
{
    public function routeAction(User $user): UserDto
    {
        return $this->respondWithSingle($user);
    }

    protected function respondWithSingle(User $user): UserDto
    {
        return UserDtoFactory::from($user);
    }
}
```

**The dispatch is resolved at build time, never at request time.** An operation is exactly one HTTP method, so
`GET /users/{id}` is never a POST and a `match` on the request method would be four dead branches. The build knows which
method the operation declares and emits the one call that applies.

**Two methods rather than one, and the split earns its keep on writes.** For a single read the two signatures are
identical, so the indirection buys nothing there and is kept only for consistency. On a write it is the whole point:

```php
public function routeAction(StoreUserRequest $request): UserDto
{
    return $this->create($request->validated());
}

protected function create(array $validated): UserDto
{
    return UserDtoFactory::from($this->getQuery()->create($validated));
}
```

A child overriding `create()` receives `array $validated` and never touches the request object or the route signature.
`routeAction` owns the HTTP seam; the CRUD method owns the work.

### `x-model` is what turns on everything model-shaped

**Decision: `x-model` is the switch for the whole persistence layer.** Declaring it gives an operation a model contract,
a default query, CRUD defaults and a DTO factory. Declaring nothing gives it none of them, and its `routeAction` throws
[the `501`](./code-generation.md#an-unimplemented-operation-answers-501) rather than pretending.

| The operation declares | Generated controller gets                                                            |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `x-model`              | The model contract, a query, the CRUD method its semantic implies, and a DTO factory |
| Nothing                | A `routeAction` that throws, and no CRUD interface at all                            |

**Without `x-model` there is no DTO factory either**, and the reason is worth being precise about: a factory's default
mapping works by matching a DTO's properties against a **source**, and with no model declared there is no source to
match against. The build would have nothing to write. So the DTO class is still generated from the response schema, and
constructing it becomes entirely the custom controller's job.

**Which is not the same as the package doing nothing for you.** A no-`x-model` operation still gets its route, its
[`FormRequest`](../project/roadmap.md) derived from the request body schema, and its DTO class derived from the response
schema — everything the specification can state on its own. What it does not get is a guess about persistence. **"No
`x-model`" does not mean the package will not help you; it means the package will not guess at your persistence.**

That framing is the whole shape of this package, and it is worth stating once plainly: `x-model`, and
[rate limiting](./rate-limiting.md) and [pagination](./pagination.md) beside it, are **extras that remove redundancy**.
They go as far as replacing everything that is obvious, so that a developer spends their attention on what actually
carries value. They are not the point of the package, and nothing breaks without them.

### The model contract is an interface; a trait supplies what it can

**Decision: an interface declares the model contract, and a trait ships the implementation that can be shared.** The
interface is the type — a trait is not one, which is the whole reason both exist:

```php
// The type. What anything reasoning about a model-backed controller depends on.
interface HasModel
{
    public function getModelClass(): string;

    public function getQuery(): Builder;
}

// Shipped by the package. Only the part that can be written once, for everyone.
trait InteractsWithModel
{
    public function getQuery(): Builder
    {
        return ($this->getModelClass())::query();
    }
}
```

The generated class then supplies the one fact only it knows:

```php
final class GetUsersIdController extends SpecController implements HasModel, ShowsResource
{
    use InteractsWithModel;

    public function getModelClass(): string
    {
        return User::class;
    }

    public function routeAction(User $user): UserDto
    {
        return $this->respondWithSingle($user);
    }

    protected function respondWithSingle(User $user): UserDto
    {
        return UserDtoFactory::from($user);
    }
}
```

**This interface carries real methods, unlike
[the CRUD markers below](#the-detected-crud-semantic-is-a-marker-interface-deliberately-empty), and the difference is
not a matter of taste.** `getModelClass()` and `getQuery()` take **no parameters**, so an implementation can satisfy
them exactly and a child can override them freely. `respondWithSingle(User $user)` does take one, and PHP requires
parameter types to be contravariant, so an interface declaring `respondWithSingle(Model $model)` would forbid the
narrowly-typed generated method above. **A parameterless method can be a contract; a method that receives the bound
model cannot.** That single rule decides which family an interface belongs to, so neither is an inconsistency.

**Only `getModelClass()` is generated**, because it is the only part that differs per operation. `getQuery()` lives in
the trait so its default exists in one place rather than repeated across every model-backed controller — and it stays
the seam a project overrides most often: a global scope, eager loading, hiding soft-deleted rows are all legitimate
query construction that never contradicts what the specification declared.

### The detected CRUD semantic is a marker interface, deliberately empty

**Decision: alongside `HasModel`, the generated class implements an empty interface naming the CRUD semantic the build
detected** — one interface per semantic, each declaring no methods at all.

Empty follows directly from
[the parameterless rule above](#the-model-contract-is-an-interface-a-trait-supplies-what-it-can): a CRUD method receives
the bound model, so no interface can declare it without giving up the narrow typing. And even where it could, it should
not — a method-carrying interface would constrain the shape of every override, which is exactly the freedom this design
means to leave open. **The only contract that matters is `routeAction`'s return type**, and PHP already enforces that.
Constraining convenience past it is how a design gets rigid for nothing.

What the marker buys over the docblock that already states the same finding:

- **It is on the declaration line**, so the semantic is the first thing anyone reads, human or agent.
- **It is greppable and `instanceof`-able**, without parsing a comment or reflecting over an attribute.
- **It is where the vocabulary is defined.** The interface's own docblock explains what this package means by a create,
  a collection read, and so on, so go-to-definition lands on the explanation rather than on nothing. That is the reason
  it is an interface rather than an attribute.

**And no interface is a signal too.** An operation the build could not read a semantic from implements none, and its
`routeAction` throws. The declaration line therefore tells the whole story either way.

**One framing precision, because the marker could otherwise lie.** A custom child inherits it and is free to reimplement
`routeAction` as something else entirely. So the interface documents **what the build detected in the specification**,
never what the class does — it is a [finding](./code-generation.md#every-generated-file-explains-itself), in the same
sense the docblock's findings are, and it stays true no matter what a developer writes on top of it.

**Open:** the names. `HasModel`, `InteractsWithModel`, `CreatesResource`, `UpdatesResource`, `DeletesResource`,
`ShowsResource` and `ListsResources` are working names, and each is public API surface under
[rule 4](./openapi-support.md#the-four-rules) from the first release on. Settling them before that release costs
nothing; after it, adding a member to any of them is a breaking change.

### How the semantic is detected

Two signals, and each answers a different question. Neither is a heuristic on words.

| Question                    | Signal                                                                                                                                                                                                                                            |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Single item or collection?  | **The response schema.** `type: array` is a bare list; a `type: object` whose [configured `mapping.collection` key](./pagination.md#one-built-in-driver) names an array property is an enveloped, paginated list; anything else is a single item. |
| Creating, or acting on one? | **Whether the path binds the model.** No parameter means the resource does not exist yet; a parameter means it does.                                                                                                                              |

The response schema decides the read shape rather than the path, and the counter-example is what settles it:
`GET /users/{id}/invoices` carries a parameter **and** returns a collection. The parameter says what is addressed, the
schema says what comes back, and for choosing between a single and a collection it is what comes back that matters.
Hardcoding the envelope property to `data` instead of reusing the pagination mapping would be the same
two-sources-of-truth failure this document keeps avoiding.

The path parameter settles the write side:

| Operation                   | Parameter | Detected |
| --------------------------- | --------- | -------- |
| `POST /users`               | no        | create   |
| `POST /users/{id}/activate` | yes       | nothing  |
| `PATCH /users/{id}`         | yes       | update   |
| `DELETE /users/{id}`        | yes       | delete   |

**A POST on an already-addressed resource is an action, not a creation**, so the build detects nothing and the operation
answers `501` rather than mass-assigning into a model that already exists. Guessing there would not be a harmless
mistake: `POST /users/{id}/activate` with `x-model: User` would create a bogus record. And a developer whose endpoint
genuinely departs from these semantics is free to declare `x-controller` and write whatever the operation actually does
— which is the escape hatch, working exactly as intended.

**`PUT` and `PATCH` share `update()`.** The difference is what the request must carry, not what the controller does, so
it lives in the [generated `FormRequest`](../project/roadmap.md): `PUT` requires the full body, `PATCH` makes fields
optional. One honest caveat, because it is a real semantic gap most APIs ignore: strict `PUT` replaces the resource, so
an absent field should return to its default, and `$model->update($validated)` does not do that. A project that needs
replacement semantics overrides `update()`.

### The factory is recommended, not imposed

The generated CRUD method calls the operation's
[DTO factory](./code-generation.md#factories-not-subclasses-are-where-behavior-lives), and **the generated code says in
a comment that keeping that call is strongly recommended.**

A comment rather than a constraint, and the reason is worth stating so it does not read as softness: **bypassing the
factory does not break anything.** A child that overrides `respondWithSingle()` and builds its DTO by hand still returns
a `UserDto`, and the return type already enforces the only thing the contract cares about. The factory is where mapping
belongs, which is a separation-of-concerns argument rather than a correctness one — and this package does not put a
structural barrier in front of a choice that cannot go wrong.

### Middleware is a method, not a separate mechanism

**Decision: `SpecController` implements Laravel's own `HasMiddleware` interface, with a default `middleware()` returning
nothing.** A project needing middleware on one operation overrides that method in its custom child, so middleware is the
same shape as every other extension point rather than a second thing to learn — no `implements` clause to remember, and
one list of overridable methods to read.

**Everything the specification derives stays on the route, never in `middleware()`.** That is already what
[`security.md`](./security.md#one-middleware-one-question-does-the-model-have-the-scope) decided for the scope check,
and keeping it there is what makes overriding `middleware()` safe: Laravel combines route middleware with controller
middleware rather than replacing one with the other, so **a child cannot drop its own security by forgetting
`parent::middleware()`**. There is nothing of ours in that method to preserve.

Two consequences of using Laravel's interface as-is: `middleware()` is `static` where the others are instance methods,
and being `static` it cannot reach instance state — so middleware stays declarative, which is the right constraint
anyway.

### `x-model` and `x-controller` are independent

The two extensions answer different questions and neither implies the other:

| Declares             | What the operation gets                                                                |
| -------------------- | -------------------------------------------------------------------------------------- |
| `x-model` alone      | A working default with no code written at all, and a `final` generated controller      |
| `x-controller` alone | A custom child that owns `routeAction` entirely, over a parent that throws             |
| Both                 | A working default a custom child can override method by method                         |
| Neither              | A `501`, which is the honest answer when nothing has said what the operation should do |

The third row is the common case for anything interesting: the defaults handle the shape, and the child takes over only
the part that earns a developer's attention.

## Reads, and where they stop needing a line of code

A single-resource read needs no override at all. Laravel's own implicit route-model binding resolves it: **the generated
method's parameter is type-hinted with the model class**, a build-time decision, and Laravel does the actual binding at
request time with no package code involved — consistent with
[the runtime never seeing the spec](./code-generation.md#the-runtime-never-sees-the-spec). The type hint comes from
`x-model`, so one value in the specification feeds three things: this hint, the factory the generated method calls, and
`getQuery()`'s default source. One fact, three consumers, never two answers.

A collection read has nothing bound to receive, so its `routeAction` takes no model and `respondWithCollection()` starts
from `$this->getQuery()`, handed to [pagination's built-in driver](./pagination.md#one-built-in-driver) when the
operation's response declares a page.

**Decision: this package ships no filtering or sorting.** OpenAPI has no vocabulary for a filter DSL any more than it
does for [row-level authorization](./security.md#past-the-scope-check-it-is-a-policys-job), and the same test applies:
inventing one would be a far larger commitment than anything else in this document set, for a feature every serious
Laravel project already reaches for a dedicated package to solve. `getQuery()` is already the hook — override it and
wire in Spatie's Query Builder, Scout, or whatever the project already uses.

## Writes, by the same default

**Decision: `create`, `update` and `delete` get the same treatment as reads** — a generated default built from `x-model`
and the request's already-validated data, and a custom child free to override either the CRUD method or `routeAction`
for anything more than plain mass assignment:

| Detected | Generated default                                     |
| -------- | ----------------------------------------------------- |
| Create   | `$this->getQuery()->create($validated)`               |
| Update   | `$model->update($validated)`, `$model` bound as above |
| Delete   | `$model->delete()`, `$model` bound as above           |

`$validated` is what the [generated `FormRequest`](../project/roadmap.md) already produced from the operation's request
body schema — nothing new reads the specification a second time.

**This is where the doctor earns its keep.** Mass assignment silently drops whatever a model's `$fillable` (or
`$guarded`) does not allow — Eloquent does not raise for it. A request body schema declaring a field the model will not
accept is therefore invisible at the wire and only ever noticed as "why didn't this save," far from its cause.
**Decision: the doctor compares a model-aware operation's validated fields against the bound model's mass-assignment
rules**, and reports the mismatch by name — the same shape as
[the security scheme naming contract](./security.md#scheme-names-are-a-naming-contract-with-your-guards): a relationship
that has to hold between two files the specification cannot itself see across.

Anything past plain mass assignment is an ordinary override, and the child chooses which seam to take it at:

```php
// In the custom child. Override the CRUD method to keep routeAction's plumbing.
protected function create(array $validated): OrderDto
{
    app(PaymentService::class)->charge($validated);

    return parent::create($validated);
}
```

**Decision: no opt-out, and no way to force `501` on a write `x-model` already gates.** The tempting worry is a
`POST /orders` that saves the row and returns `200` while nothing charged the card. That is not a defect in this default
— it is the boundary this package has been honest about from the start. **Neither the specification nor this package is
magic.** They own how a client talks to the application over HTTP; a developer owns what happens once a request arrives,
exactly as [`security.md`](./security.md#past-the-scope-check-it-is-a-policys-job) draws the same line for
authorization. A flag to suppress the default would only protect a developer who shipped `x-model: Order` on a
payment-charging endpoint without ever opening the generated file, and building for that case would mean designing
around the wrong audience rather than trusting the one this package is for.

## Where pagination attaches

Nowhere new, and it splits along the same line everything else here does. `respondWithCollection()` maps a page into the
envelope, and a second seam says where the page comes from:

| Seam                                         | Depends on `x-model`                             |
| -------------------------------------------- | ------------------------------------------------ |
| `getPaginator(): Paginator\|CursorPaginator` | Yes for its default body; otherwise it throws    |
| `respondWithCollection()`                    | No — the envelope comes from the response schema |

**Which means an operation can paginate with no model at all.** A proxy in front of an upstream paginated service
overrides `getPaginator()`, returns one of
[Laravel's own pagination contracts](./pagination.md#laravel-already-owns-the-source-agnostic-contract), and keeps the
generated envelope mapping. [`pagination.md`](./pagination.md#how-a-page-is-produced) owns the detail.

`getPaginator()` is parameterless, so it belongs to
[the interface family that can carry a real contract](#the-model-contract-is-an-interface-a-trait-supplies-what-it-can)
rather than to the empty markers — the same rule, applied again.

That is the practical payoff of dropping the context objects. The earlier design had to answer "how does a capability
attach to a context" as a design question; now the build writes the calls it decided on, and the generated file's own
[docblock](./code-generation.md#every-generated-file-explains-itself) names what it detected and why.

## The doctor counts two things, not three

**Decision: the coverage report separates `501` from everything that works, and stops there.**

```
12 operations answer 501
8 operations work
```

`501` is a real gap: nothing answers the request. Everything else is not a gap, whether it runs on a generated CRUD
default or on a custom child — **the doctor exists to find failures, not to narrate every file that is fine.** Whether a
working operation is a default or an override is already answered by that file's own
[docblock](./code-generation.md#every-generated-file-explains-itself), the moment anyone opens it. Repeating the
distinction in the coverage report would be the doctor auditing something the code already says about itself, which is
not what [rule 2](./openapi-support.md#the-four-rules) asks for: it asks that a gap be loud, not that every working file
be annotated twice.

Two checks specific to this document, both of which the specification cannot see for itself:

- **An `x-controller` naming a class that does not exist.** The specification promised a custom controller and nothing
  provides it. The fix is `spec:make`, and the report says so.
- **A custom child whose `x-controller` value has changed**, leaving it extending a parent the build no longer emits.
  Reported as an orphan, by name, with the file and line — the same
  [rename reporting](./code-generation.md#how-it-says-it) already decided.

## Open questions

- Whether `spec:make` needs a non-interactive form for CI, given that
  [the insertion prompt](#specmake-is-the-only-way-in) assumes somebody is at the keyboard. A `--no-interaction` run
  presumably prints the block and exits without writing, which is the safe default but worth stating rather than
  inferring.
- The [interface, trait and method names](#the-detected-crud-semantic-is-a-marker-interface-deliberately-empty), all of
  which are public API surface under [rule 4](./openapi-support.md#the-four-rules) from the first release on.
- Whether a generated DTO could be a Laravel API Resource instead. The `routeAction` return type is the only contract
  either way, so this is a question about what the build emits rather than about this document's design — worth settling
  against real generated output rather than in the abstract.
