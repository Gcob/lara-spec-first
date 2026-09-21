---
title: Anatomy of a DTO
audience: Users
covers: >
    What every generated DTO looks like, request side and response side alike: its three members, the `Optional`
    sentinel that carries an absent field, which PHP type each schema type becomes and why an enum stays a scalar, what
    the design takes from `spatie/laravel-data` and what it leaves there, why none of that package's runtime engine is
    needed once the build knows the shape, and why the package does not depend on it.
read_before: >
    Implementing the DTO emitter on either side, or adding a member or a runtime class that generated DTOs would use.
tags: [code-generation, openapi, decisions, dependencies, laravel]
---

# Anatomy of a DTO

> **In brief**
>
> - **Not built yet.** Nothing emits a DTO today; this is the shape Phase 2 will follow on both sides.
> - A DTO is a constructor, a `from()` and a `toArray()`, and the build writes all three in full.
> - A field the client did not send is `Optional`, a class of this package, and `toArray()` leaves it out.
> - The shape is borrowed from `spatie/laravel-data`. Its engine is not, because the build already knows what that
>   engine discovers at run time.
> - The package does not depend on `spatie/laravel-data`, and a generated DTO extends nothing.

A DTO is the contract's shape as a PHP type.
[`request-validation.md`](./request-validation.md#the-payload-arrives-as-a-dto) owns why the validated payload becomes
one, and [`response-dtos.md`](./response-dtos.md) owns why a response is built from one and who builds it. This file
owns what is inside the class, which is the same on both sides.

> **None of this is behavior yet.** The build emits no DTO. The request side lands with
> [#35](https://github.com/Gcob/lara-spec-first/issues/35) and the response side with
> [#39](https://github.com/Gcob/lara-spec-first/issues/39). Items marked `Open` are undecided, and the number beside one
> links to the card that settles it.

## One class, three members

**Every generated DTO is a `final readonly` class with a promoted constructor, a static `from()` and a `toArray()`, and
nothing else.** This is the whole of one, generated from a `User` schema:

```php
// app/Http/Generated/Data/UserDto.php
final readonly class UserDto implements Arrayable, JsonSerializable
{
    /**
     * @param 'active'|'banned' $status
     * @param list<RoleDto> $roles
     */
    public function __construct(
        public int $id,
        public string $email,
        public ?string $nickname,
        public string $status,
        public CarbonImmutable $created_at,
        public array $roles,
        public Optional|AddressDto $address,
    ) {}

    public static function from(array $payload): self
    {
        return new self(
            id: (int) $payload['id'],
            email: $payload['email'],
            nickname: $payload['nickname'],
            status: $payload['status'],
            created_at: CarbonImmutable::parse($payload['created_at']),
            roles: array_map(RoleDto::from(...), $payload['roles']),
            address: array_key_exists('address', $payload)
                ? AddressDto::from($payload['address'])
                : new Optional(),
        );
    }

    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'email' => $this->email,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'created_at' => $this->created_at->toRfc3339String(),
            'roles' => array_map(fn (RoleDto $role) => $role->toArray(), $this->roles),
        ];

        if (! $this->address instanceof Optional) {
            $array['address'] = $this->address->toArray();
        }

        return $array;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
```

What each member is for:

1. **The constructor is the shape.** One promoted property per schema property, typed from the schema, so a contract
   change is a changed signature and a static analysis error in the code that reads it.
2. **`from()` takes an array and nothing else.** On the request side it is fed `validated()`; on the response side a
   [factory](./response-dtos.md#factories-carry-the-behavior) may call it or the constructor, whichever reads better for
   the source it maps from. Building a DTO from a model is the factory's job, never the DTO's.
3. **`toArray()` writes the contract's keys back out**, recursing into nested DTOs and leaving out every `Optional`.
   `jsonSerialize()` returns the same array, so a DTO returned from a controller becomes a JSON response through
   Laravel's own router with nothing registered.

**A property is named exactly like the contract's key** when the key is a valid PHP identifier, so `created_at` in the
specification is `$created_at` in the class and one `grep` finds both. A key that is not an identifier (`user-id`,
`2fa`) gets a derived name, and `from()` and `toArray()` keep the key as written. Two keys deriving the same name are a
hard error at build time naming both, never a silent pick. The derivation itself is public API surface under
[rule 4](../openapi-support.md#the-four-rules) and belongs to
[the name freeze](../../../README.md#before-10-freeze-what-a-major-would-cost).

**The scalar casts in `from()` are safe because validation has already run.** A `multipart/form-data` body delivers
`"42"` where the schema said `integer`, and Laravel's `integer` rule accepts it; `(int)` is what lets the typed property
accept it too, and it cannot turn a bad value into a wrong one, since a bad value never got past the rule set.

**Not `Responsable`, deliberately.** A response's status code and headers belong to the operation, not to the shape, and
one `$ref` schema is routinely returned by operations answering `200` and `201`. A DTO that decided its own status would
have to pick one of them.

## Absent is `Optional`

**A property the client may leave out is typed `Optional|T`, and `Optional` is a class this package ships**:

```php
// vendor/gcob/lara-spec-first/src/Data/Optional.php
namespace Gcob\LaraSpecFirst\Data;

final readonly class Optional {}
```

It carries no state and has no behavior. It exists so that "not sent" is a type a controller can test for, distinct from
`null`, which the schema's `nullable` already owns:

```php
if (! $data->nickname instanceof Optional) {
    $user->nickname = $data->nickname; // may still be null, which the client sent on purpose
}
```

**The name and the `instanceof` test are `spatie/laravel-data`'s**, on purpose: a developer who has used that package
reads the generated type without looking anything up. Which property gets `Optional` on the request side, and why a
`PATCH` gets its own type for it, is
[`request-validation.md`](./request-validation.md#an-absent-field-is-a-third-state)'s subject.

**It is the one class of this package a generated DTO imports**, and that makes its name and namespace public API
surface under [rule 4](../openapi-support.md#the-four-rules) from the first release that emits a DTO.

**Open ([#39](https://github.com/Gcob/lara-spec-first/issues/39)):** whether a property a response schema leaves out of
`required` becomes `Optional|T` or plain `T`. Plain `T` is stricter than the contract and still conforms to it, since a
server that always sends a field breaks no promise, and it spares a factory from ever writing `new Optional()`. The cost
is that a server can then never omit a field the contract allows it to omit. The lean is `Optional|T`, for the same
reading on both sides.

## What each schema type becomes

**A schema type maps to one PHP type, and the mapping is fixed.** It reads a
[`Contract\Schema`](../openapi-support.md#the-normal-form-a-schema-takes), so nothing below depends on which OpenAPI
version wrote the document:

| The schema says                                                              | The property is                | `from()` does                   | `toArray()` does    |
| ---------------------------------------------------------------------------- | ------------------------------ | ------------------------------- | ------------------- |
| `string`                                                                     | `string`                       | Reads it                        | Writes it           |
| `integer`                                                                    | `int`                          | `(int)`                         | Writes it           |
| `number`                                                                     | `float`                        | `(float)`                       | Writes it           |
| `boolean`                                                                    | `bool`                         | `(bool)`                        | Writes it           |
| `string`, `format: date-time`                                                | `CarbonImmutable`              | `CarbonImmutable::parse()`      | `toRfc3339String()` |
| `string`, `format: date`                                                     | `CarbonImmutable`              | `CarbonImmutable::parse()`      | `toDateString()`    |
| `enum`                                                                       | Its scalar type                | Reads it                        | Writes it           |
| `object` with `properties`, via `$ref`                                       | The DTO named after the schema | `::from()`                      | `->toArray()`       |
| `object` with `properties`, inline                                           | A DTO named after its parent   | `::from()`                      | `->toArray()`       |
| `object` with no `properties`                                                | `array<string, mixed>`         | Reads it                        | Writes it           |
| `array` with `items`                                                         | `list<T>`, in the docblock     | `array_map()` when `T` is a DTO | The same, back      |
| A file part                                                                  | `UploadedFile`                 | Reads it                        | Never on this side  |
| A node that [recurses](../openapi-support.md#the-normal-form-a-schema-takes) | The ancestor's DTO             | `::from()`                      | `->toArray()`       |
| `nullable`                                                                   | `?T`                           | Keeps the `null`                | Writes the `null`   |

**A file part is the request side only**, and what reaches the column after it is [`uploads.md`](../uploads.md)'s. A
schema a generator [does not honor](../openapi-support.md#schemas) never reaches this table: it has already been
reported, by name, before any class was written.

### An enum stays a scalar

**An `enum` becomes its scalar type with the allowed values in the docblock, `@param 'active'|'banned' $status`, and no
generated PHP enum.** Larastan reads that literal union and reports a comparison against a value the contract never
allowed, which is most of what a native enum would buy.

A generated enum was the alternative, and three costs sent it away:

1. **Another name under [rule 4](../openapi-support.md#the-four-rules) for every `enum` in the contract**, inline ones
   included, each needing a derivation rule of its own.
2. **A case name derived from a value nobody chose as an identifier.** `in-progress`, `2fa` and `""` are all legal enum
   values, and every one of them needs a spelling invented for it.
3. **A second type to convert through.** Eloquent, the validator and the JSON already speak the scalar, so a native enum
   is a `->value` at every boundary it crosses.

A project that wants a native enum still gets one: it declares it, and its factory or its controller converts at the
point where it cares.

## Taken from `spatie/laravel-data`, and left there

**The shape is borrowed, and the engine is not.** `spatie/laravel-data` is what most Laravel projects already reach for
to write a DTO, and its public surface is the right one. What the design keeps and what it drops, feature by feature:

| In `spatie/laravel-data`                                | Here                                                                                            |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| A promoted constructor as the shape                     | **Kept**, as a `final readonly` class                                                           |
| `from()`                                                | **Kept**, from an array only, and written in full by the build                                  |
| `toArray()` and JSON serialization                      | **Kept**, through `Arrayable` and `JsonSerializable`                                            |
| `Optional`                                              | **Kept**, as [a class of this package](#absent-is-optional)                                     |
| Nested data objects and typed collections               | **Kept**, as nested DTOs and a `list<T>` docblock on a plain array                              |
| Casts and transformers                                  | **Kept** as [generated lines](#what-each-schema-type-becomes), never as a registry              |
| Name mapping (`MapInputName`, `MapOutputName`)          | **Kept** only where a key is not a PHP identifier                                               |
| Validation rules inferred from the class                | **Left.** [The generated `FormRequest`](./request-validation.md) owns validation                |
| `Lazy` properties, includes and excludes                | **Left.** The contract fixes which fields a response carries                                    |
| Wrapping (`wrap('data')`)                               | **Left.** An envelope is a property of the schema, and the DTO already has it                   |
| `Responsable`, `DataCollection`, paginated output       | **Left.** Status belongs to the operation, and paging to [`pagination.md`](../pagination.md)    |
| Magic creation methods (`fromModel()`, `fromRequest()`) | **Left.** [A factory](./response-dtos.md#factories-carry-the-behavior) builds from a model      |
| Computed properties, `additional()`                     | **Left.** A DTO has no behavior, and nothing outside the contract belongs in one                |
| Attributes, the structure cache                         | **Left.** There is nothing to discover at run time                                              |
| The TypeScript transformer                              | **Left.** [`openapi-typescript`](./publishing.md#types-come-from-openapi-typescript) owns types |

## The build knows what Spatie has to discover

**`spatie/laravel-data` is heavy because it works out at run time what this build already knows at build time.** A
`Data` class is written by hand, so the package has to reflect over it on every request to learn its properties, their
types, their names on the wire and how to cast each one. That is what its attributes declare, its pipelines execute and
its structure cache memoizes.

A generated DTO has none of that to learn. The build read the schema, so it writes the cast, the key and the nested
`::from()` as plain lines of PHP, and the class explains itself to anyone who opens it. It is the same reasoning as
[the runtime never seeing the spec](./index.md#the-runtime-never-sees-the-spec), applied one level down: the decision is
made once, where the information is, and the request path runs its result.

**Which is also why the design is not over-engineered, despite borrowing from a large package.** A DTO costs one import
at run time, `Optional`, and does no reflection, reads no configuration and keeps no cache. Everything that makes
`spatie/laravel-data` large sits in the rows the table above leaves behind.

## Why it is not a dependency

**The package does not require `spatie/laravel-data`, and no generated class extends or imports anything from it.** Four
reasons, the first of which would settle it alone:

1. **A `Data` object cannot be `readonly`.** `Data` carries a mutable context of its own, and PHP refuses a `readonly`
   class that extends a class which is not. Depending on it would mean giving up the
   [`final readonly` DTO](./response-dtos.md#the-shape-is-ours-the-behavior-is-yours) the rest of this design rests on.
2. **Its engine would run for nothing.** Every row the [table](#taken-from-spatielaravel-data-and-left-there) keeps is a
   line the build can write, so the dependency would ship a reflection pipeline whose only job is rediscovering the
   schema.
3. **Generated code would follow another package's releases.** A DTO's public surface would move with that package's
   majors, and so would the transitive packages it brings with it, into every project that installs this one.
4. **The emitter stays small.** A plain class is one the emitter writes entirely and a test checks by reading its
   output. A subclass of `Data` would bend every emitted line to that hierarchy's conventions, and would have to be
   tested against that package's behavior as well as this one's.

**Depending on it for `Optional` alone was the narrower alternative, and it was dropped for the same third reason.** It
would tie the type of every optional property in every generated DTO to another vendor's class, in exchange for a class
with no body.

## What this document does not cover

Four things a reader could expect here, each owned elsewhere:

1. **Which rules validate a payload before `from()` sees it.** [`request-validation.md`](./request-validation.md), which
   also owns which properties are `Optional` and why a `PATCH` gets a partial type.
2. **How a response DTO gets built from a model, and how a project overrides that.**
   [`response-dtos.md`](./response-dtos.md#factories-carry-the-behavior)'s factories.
3. **Whether a schema keyword is honored at all.** [`openapi-support.md`](../openapi-support.md#schemas), whose levels
   decide what reaches this page.
4. **The exact spelling of a generated name.**
   [`generated-file-anatomy.md`](./generated-file-anatomy.md#naming-and-the-rename-problem), and the
   [name freeze](../../../README.md#before-10-freeze-what-a-major-would-cost) that fixes it before a `1.0`.
