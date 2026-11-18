---
title: Response DTOs
audience: Users
covers: >
    How a response schema becomes a PHP type, which statuses get one and what the generated controller says about the
    ones that do not, why a DTO is `final readonly` and therefore has no abstract layer to extend, why hydration lives
    in a generated factory instead, how a project overrides one of those factories by extending it from a directory it
    declares, why exactly one override per factory is allowed, and what a factory's docblock has to carry that no other
    generated file does.
read_before: >
    Reading a response DTO in a controller, implementing the factory emitter, or the scan that finds an override.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Response DTOs

> **In brief**
>
> - **The DTO is built, the factory is not.** `spec:build` writes one response DTO per documented response shape, in the
>   `Data` sub-namespace. No factory is generated yet, and the factory half of this page is the design that
>   [#40](https://github.com/Gcob/lara-spec-first/issues/40) will follow.
> - **What it reads already existed.** A response's schema reaches the contract
>   [normalized and version-free](../openapi-support.md#the-normal-form-a-schema-takes), per status code and per media
>   type, so nothing here has to open a specification or know which OpenAPI version wrote it.
> - The shape is generated and the behavior is yours. That tension is the whole design.
> - A DTO is `final readonly`, so there is nothing to extend. Building one happens a step away, in a factory.
> - You override a factory by extending it from a directory you declare, and the generated default never disappears.
> - Two classes extending the same generated factory is a hard error rather than a silent pick.

A response schema describes a shape, and an application has to build that shape out of whatever it actually holds. This
file owns the split that follows: the type the build owns entirely, and the class beside it that a project is meant to
teach.

> **The DTO is behavior and the factory is design.** The build emits a DTO per documented response shape today, from the
> response schemas the document writes, and no factory: no configuration key exists to point the override scan anywhere.
> What is written about factories is the design [Phase 2](../../../README.md#phase-2-the-generated-pipeline) will
> follow. Items marked `Open` are undecided, and the number beside one links to the card that settles it.

## The shape is ours, the behavior is yours

The DTOs are how a response schema becomes a PHP type. Two properties, and the tension between them is the design:

- **The shape is generated, and not yours.** Properties, types and nullability come from the response schema. A
  hand-edited shape is drift from the contract by definition, and it is exactly what Spec-First exists to prevent.
- **The behavior is yours.** Hydration is where real applications differ, and a generated DTO you cannot teach to build
  itself from your model is a generated DTO people will wrap or abandon.

**A DTO is `final readonly`.** It is a value object mirroring a piece of the contract, not a class with behavior of its
own to grow, the same reasoning that makes
[`Operation`](https://github.com/Gcob/lara-spec-first/blob/main/src/Contract/Operation.php) and its neighbors
`final readonly` in this package's own types. Which rules out the
[two-layer split](./index.md#an-interface-and-an-abstract-class) that customization elsewhere in this subject relies on:
there is no abstract DTO to extend, because there is no DTO to extend, full stop.

**It extends nothing from `spatie/laravel-data` either.** The class borrows that package's shape and none of its engine,
and what is inside it, the same on the request side, is [`dto-anatomy.md`](./dto-anatomy.md)'s subject.

## What the build writes for a response

**A status that has a JSON body gets a DTO, and the generated controller names it.** `spec:build` reads each declared
status (`200`, `201`, `2XX`, `default`, and the `4xx` and `5xx` ones) of every operation. A status whose content has a
JSON media type, `application/json` or any `+json` type, with an object schema, or a list of them, gets one
`final readonly` class under the `Data` sub-namespace, beside the input DTOs and pruned with them: remove the response
from the contract and the next build deletes its file. What is inside the class, and how it is named, is
[`dto-anatomy.md`](./dto-anatomy.md)'s subject.

**One schema is one class, however many operations return it.** Two operations returning `$ref: User` share `UserDto`,
and its findings list each reader with the status it answers: `Read by get /users/{id} (200)`. A response and a request
built from one schema are two classes (`UserDto` and `UserInputDto`), and a response schema that would take an input
DTO's name has no DTO, and says which schema keeps the class.

**A class name two schemas derive goes to the schema that outranks the others.** A response schema can derive a class
name that another schema derives too: an inline `address` in `User` and a component `UserAddress` are both
`UserAddressDto`, and a response schema `UserInput` and a request schema `User` are both `UserInputDto`. An input DTO
outranks every response schema, and a component outranks a schema written inline. The schema at the top rank keeps the
name, and every other gives way: its status has no DTO, or its property is `mixed`, and the file says which schema keeps
the class. **Two schemas at the top rank both give way**, as with two components `new-user` and `NewUser`, or two inline
objects of different shapes that derive one name: picking one would pick by the order the document writes them. Two
inline objects that say the same thing share one class. The document's order changes nothing, because the build reads
every response schema once to see who claims each name before it builds, so reading the contract backwards writes the
same classes with the same types. What still follows the document is the `Read by` line of a class that several
operations return, which lists them in the order the document writes them, and, for a class that two inline objects
share by shape, which of the two the file's provenance names. A component whose keys collide or whose `allOf` branches
disagree still claims its name, since a class that carried it would describe something else, and the schema that gives
way is told that component gets no DTO either. The build is not refused.

**A known limit:** a schema reached only through a schema that gave way still claims its class name. If an inline schema
derives that name, it gets no DTO and its finding names a class that is not written, in either order of the document. A
component is caught the same way: a healthy `NewPet` that ties with a `new-pet`, reachable only through a schema that
gave way and never written, loses its DTO too ([#191](https://github.com/Gcob/lara-spec-first/issues/191)).

**A status with no DTO says why, in the controller.** The generated controller's findings carry one sentence per status:

```text
The `200` response is `UserDto`, in `Data/UserDto.php`.
The `200` response is a list of `UserDto`, in `Data/UserDto.php`.
The `204` response has no DTO: it declares no body.
```

The reasons a status has none are no body, no JSON media type, two JSON media types with different schemas, a body that
is not an object or a list of objects, a class name another schema keeps (above), and a schema with a fault of its own
(below). None of them refuses the build. **A response schema that also allows `null`**, `type: [object, 'null']`, still
gets the DTO of the object, and the controller's sentence for that status ends "or `null`, which no DTO holds". The DTO
does not say so itself, since one DTO serves every operation that returns the schema and not all of them may answer
`null`. A list of lists is said as one ("a list of lists of `UserDto`"), and a list whose items may be `null` says
"where an item may be `null`" rather than "or `null`", which is only said of the body.

**A schema with a fault of its own has no DTO, and says what to change.** Three faults are refused in a request and cost
a response its type only: a schema name that leaves no class name, two keys that derive one property name, and `allOf`
branches that disagree. The status says which, and how to fix it:

```text
The `200` response has no DTO: its keys `user-id` and `user_id` both derive the property `$user_id`. Rename one of them.
The `200` response has no DTO: its `allOf` branches disagree at `id`: one branch is `integer` and another is `string`. Make them agree.
The `200` response has no DTO: the schema name `123` leaves no class name. Rename it to start with a letter or an underscore.
The `200` response has no DTO: its schema `UserInput` (`#/components/schemas/UserInput`) derives the class name `UserInputDto`, which is the input DTO of the request schema written at `#/components/schemas/User`. Rename one of the schemas.
```

When two schemas of one rank derive a name, both say so, in one order whichever the document writes first: "the schema
`NewUser` (`#/components/schemas/NewUser`) and the schema `new-user` (`#/components/schemas/new-user`) both derive the
class name `NewUserDto`, so neither has it. Rename one of them."

A property whose schema has the fault is `mixed` (`Optional|mixed` when optional) in the DTO that holds it, and that
DTO's findings carry the same reason after "`address` is `mixed`:". Its other properties keep their types. **The value
that cannot be typed is the one that is `mixed`**, so a list whose items disagree is `list<mixed>`, and a map whose
values disagree is a map of `mixed`. `spec:build`, `spec:doctor` and `spec:make` go on for such a schema as `0.2.0` did,
and the doctor says nothing about it: the fix is in the contract, and the generated file says where.

**What the doctor names is what the contract says and the DTO cannot type.** A keyword a response DTO cannot type, such
as a `oneOf`, is one finding per operation, as it is in a request. A property the DTO types `mixed`, or does not carry,
for what the contract says (not for a schema at fault) is one `Partial` finding per reason and per operation: a type
that is a union, no type at all or only `null`, a recursion with no class to point at, properties written beside a
reference a recursion points back at, a target that is not an object with properties, and the values of
`additionalProperties` beside declared properties. It is named under every operation whose response reaches the DTO that
holds it, directly or through a property, a list or a map, and a value that writes a keyword the doctor names is named
by the keyword and not twice. Both exit `2`.

**`routeAction` still returns `mixed`.** The controller's findings say so beside those sentences: the types named there
are what a response is, and what `routeAction` is typed to return is a later change. Nothing yet makes a controller
return the DTO.

**What the DTO is for is the contract reaching the code that reads a response.** A response property the contract
removes is a changed constructor, so code that reads it fails static analysis, and a test runs PHPStan over such a
reader to prove it. The reasoning for the choices above is in
[ADR 0013](../../project/decisions/0013-mirror-a-response-schema-or-leave-it-untyped.md).

## Factories carry the behavior

**A note on the name, before anything else.** This "factory" is the design pattern, a class whose one job is
constructing another object, rather than Laravel's own model factories, which generate fake data for tests and carry
`HasFactory` and `Factory::class` with them. The two share a word and nothing else. Nothing here touches, extends, or
competes with `Illuminate\Database\Eloquent\Factories`.

Hydration therefore cannot live on the DTO itself. It lives one level removed, in a **factory**: an ordinary class, not
final, whose only job is turning a source (a model, an array, whatever the response needs) into the DTO.

**The build generates one factory per DTO**, mapping by naming convention, the same nomenclature-driven matching already
used [when `operationId` is absent](./generated-file-anatomy.md#deriving-a-name-without-operationid), with a default
implementation that covers the ordinary case: properties that already exist on the source, under the same name. This is
what makes the other ninety-six DTOs in a hundred-DTO contract need nothing from a developer at all.

## Overriding a factory, by extending it

Most response shapes need nothing beyond the default mapping. The few that do should not cost the other ninety-six. **A
project overrides a factory by writing a class that `extends` the generated one**, with no fixed name, no fixed file and
no service provider to touch.

**The generated factory never disappears, even once overridden.** It is not replaced, it is extended. The override would
have nothing to inherit from otherwise, and a developer would be starting from an empty file instead of a working
default mapping they only need to adjust in part. Every generated factory therefore exists for every DTO, always,
whether or not a project has ever looked at it.

The build finds the override itself, by scanning a **configured set of directories, never the whole project**, for a
class extending each generated factory, and wiring whichever it finds in place of the generated default. The directories
are named in configuration, the same shape as [`remote_references.allowed_hosts`](../remote-references.md): empty by
default, and nothing is scanned until a project says where to look. Scanning the whole application would mean touching
every autoloaded class, vendored packages included, on every build, for a feature four DTOs out of a hundred will ever
use. The cost has to be bounded by what the project actually declares, not by how large `vendor/` happens to be.

**Exactly one override per factory.** Extending a generated factory twice is not a project needing two behaviors from
one thing, it is two behaviors with no rule for which wins. The build refuses to guess: finding two classes that extend
the same generated factory is a hard error, naming both offending classes and the factory they both claim, not a silent
pick of whichever the classmap happened to load first.

This is detection **at build time**, deliberately, not a runtime `class_exists()` check scattered across every place a
DTO gets built, the same reasoning as [everywhere else in this subject](./index.md#the-runtime-never-sees-the-spec):
explicit over dynamic, and the cost paid once rather than on every request. The consequence to state plainly: an
override added without rerunning the build has not taken effect yet, a case for [drift](../doctor.md#what-it-checks),
not a new failure mode.

**Open ([#40](https://github.com/Gcob/lara-spec-first/issues/40)):** the config key's name, and whether it recurses into
subdirectories by default; the exact mechanism for finding the `extends` relationship, where reflection over the classes
the configured directories autoload is the leading answer, rather than a token scan over spellings, since an `extends`
clause needs the language's own resolution of `use` imports and aliases to be trustworthy, not a match on spelling; and
the name of the exception thrown when two classes claim one factory.

## What a factory's docblock carries

Factories follow the norm
[every generated file follows](./generated-file-anatomy.md#every-generated-file-explains-itself); what is specific to
them is what counts as a finding worth reporting. **The mapping's own result:** which properties matched the source by
name, which did not, and anything a reader should check before trusting the default, because a factory that silently
skipped a property is the one thing a reader cannot see by looking at it.

Its navigation line is the general rule applied to [the override scan](#overriding-a-factory-by-extending-it): the
`spec:make` [flag](./scaffolding.md#per-type-flags-belong-here) that scaffolds an override when none was found, replaced
by `@see` at the detected class when one was. Since the generated factory
[stays in place even when overridden](#overriding-a-factory-by-extending-it), that annotation is the only thing
distinguishing the default a reader is looking at from the behavior that actually runs.

## What this document does not cover

Three things a reader could reasonably expect here and will not find, each because it belongs to something else:

- **The request side.** One `FormRequest` per operation, derived from the request body and the query parameters, is
  [`request-validation.md`](./request-validation.md)'s subject and lands before this one, since it is what supplies the
  validated payload to everything downstream. **The DTO it hands the controller is that page's too**: a request and a
  response get separate types even when one schema produced both, and nothing here describes input.
- **Checking a response against its schema at run time.** A DTO is generated from the schema, so the shape is guaranteed
  by the build rather than verified per request:
  [the runtime never opens a specification](./index.md#the-runtime-never-sees-the-spec), and a response that has drifted
  from the contract is [the drift check](../doctor.md#what-it-checks)'s finding, not a DTO's exception.
- **The types a frontend consumes.** They come from `openapi-typescript` run over the published document, which is
  [`publishing.md`](./publishing.md#types-come-from-openapi-typescript)'s subject.
