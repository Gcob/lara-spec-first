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
> - **Both sides are built.** `spec:build` writes one input DTO per request body and one response DTO per documented
>   response shape, in the `Data` sub-namespace.
> - A DTO is a constructor, a `from()` and a `toArray()`, and the build writes all three in full.
> - A field the client did not send, or a response left out, is `Optional`, a class of this package, and `toArray()`
>   leaves it out.
> - The shape is borrowed from `spatie/laravel-data`. Its engine is not, because the build already knows what that
>   engine discovers at run time.
> - The package does not depend on `spatie/laravel-data`, and a generated DTO extends nothing.

A DTO is the contract's shape as a PHP type.
[`request-validation.md`](./request-validation.md#the-payload-arrives-as-a-dto) owns why the validated payload becomes
one, and [`response-dtos.md`](./response-dtos.md) owns why a response is built from one and who builds it. This file
owns what is inside the class, which is the same on both sides.

> **Both sides are behavior.** The request side was built by [#35](https://github.com/Gcob/lara-spec-first/issues/35)
> and the response side by [#39](https://github.com/Gcob/lara-spec-first/issues/39): what the rows below say is what the
> build writes today, and a row names the side it is about wherever the two differ. What is not built is a response
> DTO's [factory](./response-dtos.md#factories-carry-the-behavior), which is design.

## One class, three members

**Every generated DTO is a `final readonly` class with a promoted constructor, a static `from()` and a `toArray()`, and
nothing else.** This is the whole of one, generated from a `User` schema a response returns (a DTO's own docblock of
findings, which every generated file carries, is left out):

```php
// app/Http/Generated/Data/UserDto.php
final readonly class UserDto implements JsonSerializable
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
        public Optional|AddressDto $address = new Optional,
    ) {}

    public static function from(array $payload): self
    {
        return new self(
            id: $payload['id'],
            email: $payload['email'],
            nickname: $payload['nickname'],
            status: $payload['status'],
            created_at: CarbonImmutable::parse($payload['created_at']),
            roles: array_values(array_map(RoleDto::from(...), $payload['roles'])),
            address: array_key_exists('address', $payload)
                ? AddressDto::from($payload['address'])
                : new Optional,
        );
    }

    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'email' => $this->email,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'created_at' => $this->created_at->toJSON(),
            'roles' => array_map(static fn (RoleDto $item): array => $item->toArray(), $this->roles),
        ];

        if (! $this->address instanceof Optional) {
            $array['address'] = $this->address->toArray();
        }

        return $array;
    }

    public function jsonSerialize(): object
    {
        $array = [
            'id' => $this->id,
            'email' => $this->email,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'created_at' => $this->created_at->toJSON(),
            'roles' => $this->roles,
        ];

        if (! $this->address instanceof Optional) {
            $array['address'] = $this->address;
        }

        return (object) $array;
    }
}
```

What each member is for:

1. **The constructor is the shape.** One promoted property per schema property, typed from the schema, so a contract
   change is a changed signature and a static analysis error in the code that reads it.
2. **`from()` takes an array and nothing else.** On the request side it is fed the validated data, read with Laravel's
   `excludeUnvalidatedArrayKeys` off ([why](./request-validation.md#the-payload-arrives-as-a-dto)); on the response side
   a [factory](./response-dtos.md#factories-carry-the-behavior) may call it or the constructor, whichever reads better
   for the source it maps from. Building a DTO from a model is the factory's job, never the DTO's.
3. **`toArray()` writes the contract's keys back out**, recursing into nested DTOs and leaving out every `Optional`.
   `jsonSerialize()` makes a DTO returned from a controller a JSON response through Laravel's own router with nothing
   registered. On the request side it returns the same array. **On the response side it returns an object, and hands a
   nested DTO and every map to the encoder as one**: an object whose optional properties are all absent, and a map with
   no entries, go out as `{}`, never `[]`, and a map keyed `"0"` and `"1"` goes out as `{"0":"a","1":"b"}`, never
   `["a","b"]`, since the contract said object. `toArray()` stays a plain PHP array at every depth, `[]` and
   `[0 => 'a', 1 => 'b']` included, which is what mass assignment and the rest of PHP expect. **A response DTO is
   `JsonSerializable` and not `Arrayable`**: Laravel calls `toArray()` on an `Arrayable` before it encodes one, which
   `response($dto, 201)`, a paginator and `Collection::toArray()` all do, and that would write the empty map as `[]`.
   Without the interface every one of those paths reaches `jsonSerialize()`. A request DTO is both, since its
   `toArray()` is what mass assignment reads.

**A DTO is named after the schema it describes, with `Input` on the request side, and never after the `$ref` that
reached it or the file that holds it.** `#/components/schemas/NewUser` gives `NewUserInputDto` and
`NewUserPartialInputDto`, and `./other.yaml#/components/schemas/NewUser` gives the same two names, because splitting a
specification across files does not change the contract and must not rename a class a controller imports. A body written
inline takes its operation's name, `CreateUserInputDto`, and an object nested inline takes its parent's name and its
property's, `CreateUserAddressInputDto`, with `Item` added for an element of an array and `Value` for a value of a map
(`CreateUserLabelsValueInputDto`). A body that is itself a map names its value after the property that holds the body,
`additionalProperties`, so an inline object value of the body of `receiveHook` is
`ReceiveHookAdditionalPropertiesValueInputDto`. **Two different schemas that give one class are a build error**, and so
is a component name that is not a class name once it is turned into one. The refusal is not always about two schemas
sharing a name, because the generator adds a suffix to it: a component `User` also generates `UserPartialInputDto`, so a
component called `UserPartial` collides with it, and an inline `address` in `createUser` collides with the inline body
of `createUserAddress`, both `CreateUserAddressInputDto`. The message names the class, where each schema is written,
which type of each it is (full or partial), the base its name came from (the schema's name, its request, or its parent
and a property) and the suffix added to it, so it says what met rather than blaming the names. The naming scheme is not
changed to avoid it: rename one of the schemas, or give an inline schema a component name of its own. Where a schema is
written goes in the file's `Provenance`, which is where a DTO that came from another file says so.

**A response DTO is named the same way, without `Input`:** `$ref: User` gives `UserDto`, wherever the schema is written.
A response schema written inline takes its operation's name and the status, `CreateUser201ResponseDto`,
`CreateUser2XXResponseDto` and `CreateUserDefaultResponseDto`, and an object nested in it adds its property
(`CreateUser201ResponseAddressDto`), with `Item` for an element of an array and `Value` for a value of a map. Two
different schemas that give one response class do not stop the build, as they do in a request. The schema that outranks
the others keeps the name, in this order: an input DTO, a component, a schema written inline. A response schema
`UserInput` gives `UserInputDto`, which a request schema `User` gives too, and the input DTO keeps it. An inline
`address` in `User` gives `UserAddressDto`, which a component `UserAddress` keeps. Whatever does not keep the name has
no DTO, and the file that would have used it says which schema keeps the class. Two schemas of the same rank, two
components `new-user` and `NewUser` or two inline objects of different shapes, both give way, and two inline objects
that say the same thing share one class. Reading the document in either order gives the same classes and types, and only
the `Read by` lines of a shared class follow the document's order. Rename one of the schemas to type it, or give an
inline schema a component name of its own.

**A response gets a DTO per declared status** (`200`, `2XX`, `default`) that has a JSON body, `application/json` or any
`+json` type, whose schema is an object or a list of objects. A list has no class of its own, its items do
(`list<UserDto>`). A status gets none, and the generated controller says so and why, when it declares no body (`204`),
no JSON media type (`text/csv`), a list with no `items` or of values that are not objects, or a body that is neither an
object nor a list. **Two JSON media types under one status with different schemas also get none**: the build is not
refused, the controller's findings name both media types, and with one schema between them it is one DTO. Which DTO
reads which status is in the file's findings and the controller's.

**A response schema with a fault of its own gets no DTO, and the build goes on.** A schema name that leaves no class
name, two keys that derive one property name, and `allOf` branches that disagree are refused in a request, because a
rule set built from half of a contract enforces half of it. A response enforces nothing, so the status that would have
used the schema has no DTO and the controller says why and how to fix it, and a property that would have held it is
`mixed` (a list of such items is `list<mixed>`, a map of such values a map of `mixed`), with a finding in the DTO that
holds the property. Everything else in that DTO is typed as the contract writes it. A schema with such a fault is never
half built: it has no class at all, so no other type points at a class that is not there. A component whose branches
disagree or whose keys collide still claims its class name, so a schema written inline cannot take that name from it.

**A property is named exactly like the contract's key** when the key is a valid PHP identifier, so `created_at` in the
specification is `$created_at` in the class and one `grep` finds both. A key that is not an identifier (`user-id`,
`2fa`) gets a derived name, and `from()` and `toArray()` keep the key as written. Two keys deriving the same name are a
hard error at build time naming both in a request, never a silent pick. In a response the schema they sit in gets no
DTO, and the file that would have held it says which keys met. The derivation itself is public API surface under
[rule 4](../openapi-support.md#the-four-rules) and belongs to
[the name freeze](../../../README.md#before-10-freeze-what-a-major-would-cost).

**A request DTO casts its scalars, and a response DTO casts none.** The two sides differ on whether anything checked the
value before `from()` saw it:

- **On the request side, validation has already run.** A `multipart/form-data` body delivers `"42"` where the schema
  said `integer`, and Laravel's `integer` rule accepts it. `(int)` is what lets the typed property accept it too, and it
  cannot turn a bad value into a wrong one, since a bad value never got past the rule set. A boolean is read with
  `filter_var(..., FILTER_VALIDATE_BOOL)` rather than `(bool)`, because a form writes it as the text `"false"`, which
  the rule set accepts and `(bool)` would turn into `true`.
- **On the response side, nothing has.** A factory feeds `from()` from a model, where `(int) 'abc'` would be `0` and
  `(bool) 'false'` would be `true`, silently. So the value goes in as it is, and since every generated file declares
  `strict_types`, a value of the wrong type is a `TypeError` naming the property rather than a wrong number in a
  response.

**A list's elements and a map's values are not checked one by one.** PHP's `TypeError` names a property and never an
element, and `from()` hands a list or a map to its property as the array it is, converting each element only where its
type needs it (a nested DTO, a date). A `list<int>` that holds a string is built. What `from()` refuses is a property of
the wrong type, which is the one thing `strict_types` can name.

**Not `Responsable`, deliberately.** A response's status code and headers belong to the operation, not to the shape, and
one `$ref` schema is routinely returned by operations answering `200` and `201`. A DTO that decided its own status would
have to pick one of them.

**Not an API Resource either.** A `JsonResource` is built from a model by code that runs when the response is made. A
generated DTO is the contract's shape and nothing else, and what builds it from a model is the
[factory](./response-dtos.md#factories-carry-the-behavior)'s, so the shape cannot drift with that code. The reasoning is
in [ADR 0013](../../project/decisions/0013-mirror-a-response-schema-or-leave-it-untyped.md).

## Absent is `Optional`

**A property the client may leave out, or a response may leave out, is typed `Optional|T`, and `Optional` is a class
this package ships**:

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
reads the generated type without looking anything up.

**The name collides with `Illuminate\Support\Optional`**, the class behind Laravel's `optional()` helper, exactly as
Spatie's does. An IDE that auto-imports the wrong one makes `instanceof Optional` false on every call, with no error at
run time. Static analysis is what catches it: Larastan, from level 4, reports an `instanceof` against a class the
property's type can never hold as always false. The name is kept anyway, for the familiarity above: `Absent` or
`Missing` would avoid the collision and be one more word a developer coming from Spatie has to learn. Which property
gets `Optional` on the request side, and why a `PATCH` gets its own type for it, is
[`request-validation.md`](./request-validation.md#an-absent-field-is-a-third-state)'s subject.

**It is the one class of this package a generated DTO imports**, and that makes its name and namespace public API
surface under [rule 4](../openapi-support.md#the-four-rules) from the first release that emits a DTO.

**A response property the contract leaves out of `required` is `Optional|T` too**, with the same default,
`= new Optional`, so a DTO is built with every optional property absent (`new UserDto(id: 1, email: 'ada@example.com')`)
and `toArray()` leaves it out. Required properties come first and the optional ones after, each group in the order the
contract writes them, whatever order the schema lists them in. The alternative was a plain `T`, which is stricter than
the contract: a server that always sends a field breaks no promise, but a plain `T` forbids it ever omitting a field the
contract allows it to omit, and [the never-stricter principle](./request-validation.md#every-constraint-maps-or-reports)
asks for the contract's own reading. The reasoning is in
[ADR 0013](../../project/decisions/0013-mirror-a-response-schema-or-leave-it-untyped.md).

## What each schema type becomes

**A schema type maps to one PHP type, and the mapping is fixed.** It reads a
[`Contract\Schema`](../openapi-support.md#the-normal-form-a-schema-takes), so nothing below depends on which OpenAPI
version wrote the document:

| The schema says                                                              | The property is                                                                        | `from()` does                                                                                       | `toArray()` does                     |
| ---------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- | ------------------------------------ |
| `string`                                                                     | `string`                                                                               | Reads it                                                                                            | Writes it                            |
| `integer`                                                                    | `int`                                                                                  | `(int)`, request side only                                                                          | Writes it                            |
| `number`                                                                     | `float`                                                                                | `(float)`, request side only                                                                        | Writes it                            |
| `boolean`                                                                    | `bool`                                                                                 | `filter_var()`, request side only                                                                   | Writes it                            |
| `string`, `format: date-time`                                                | `CarbonImmutable`                                                                      | `CarbonImmutable::parse()`, set to the app timezone on the request side                             | `format()`, `toJSON()` on a response |
| `string`, `format: date`                                                     | `CarbonImmutable`                                                                      | `CarbonImmutable::parse()`                                                                          | `toDateString()`                     |
| `enum`                                                                       | Its scalar type                                                                        | Reads it, or casts it as its type does                                                              | Writes it                            |
| `object` with `properties`, via `$ref`                                       | The DTO named after the schema                                                         | `::from()`                                                                                          | `->toArray()`                        |
| `object` with `properties`, inline                                           | A DTO named after its parent                                                           | `::from()`                                                                                          | `->toArray()`                        |
| `object` with no `properties`                                                | `array<array-key, T>`, `T` from `additionalProperties`, `mixed` when it states none    | `array_map()` with `T`'s own conversion, keys kept (a response reads each value without casting it) | The same, back                       |
| `array` with `items`                                                         | `list<T>`, in the docblock                                                             | `array_map()` with `T`'s own conversion                                                             | The same, back                       |
| A file part                                                                  | `UploadedFile`                                                                         | Reads it                                                                                            | Writes it as is                      |
| A node that [recurses](../openapi-support.md#the-normal-form-a-schema-takes) | The class of the schema it points back at, `mixed` where the request did not unroll it | `::from()`, as above                                                                                | `->toArray()`                        |
| `nullable`                                                                   | `?T`                                                                                   | Checks for `null` before any conversion                                                             | Writes the `null`                    |
| No single type, or none at all                                               | `mixed`                                                                                | Reads it                                                                                            | Writes it                            |

**A map is typed by its values, and keeps its keys.** An object that declares no `properties` and writes a schema under
`additionalProperties` is `array<array-key, T>`, `T` being what that schema becomes: `array<array-key, int>` for
`{type: integer}`, `array<array-key, PetInputDto>` for a `$ref` to `Pet`, `array<array-key, int|null>` for a nullable
value. `from()` and `toArray()` convert each value the way they convert an element of a list, with `array_map()` and no
`array_values()`, since the keys are the data. The key type is `array-key` because PHP turns a key such as `"2024"` into
the integer `2024` when it reads the JSON, so a map of integers can arrive with an `int` key. A map that writes no value
schema, or `additionalProperties: {}`, is `array<array-key, mixed>`: the request judges nothing about its values. An
object that declares `properties` is a DTO of its own and carries only those, beside a value schema too: the request
validates the values of its other keys and `validated()` holds them, but the DTO does not carry them, and the class's
findings say so.

**A response map is typed the same way, and it is written back as a JSON object.** The same schema gives
`array<array-key, PetDto>` on a response, with `PetDto` where the request has `PetInputDto`, each value built through
`PetDto::from()` and its keys kept. A response reads each value as it is, where a request casts it. `jsonSerialize()`
writes the map with `(object)`, so an empty map is `{}` and one keyed `"0"` and `"1"` is `{"0":"a","1":"b"}`, never a
JSON array, while `toArray()` is the PHP array it was.

**A body that is a map or an open object is carried whole, under one property.** An object body with no `properties` has
no declared key for a property to be, so its keys are the data. The DTO has one property, `additionalProperties`, typed
`array<array-key, T>` and never `Optional`: an absent or empty body is `[]`. `T` is what the value schema becomes, as
for a [map property](#what-each-schema-type-becomes) (`array<array-key, int>` for `{type: integer}`), and
`array<array-key, mixed>` for an open object, whose values the request judges nothing about. `from()` hands the whole
payload to the property, converting each value, and `toArray()` returns it, so `{"a": 1, "b": 2}` is
`$dto->additionalProperties === ['a' => 1, 'b' => 2]`. The partial type is the same, since no key of it can be absent.
The reasoning is in [ADR 0015](../../project/decisions/0015-type-the-values-of-a-map-body.md).

**A response object with no `properties` is carried whole in the same way.**
`{type: object, additionalProperties: {type: integer}}` under a status is a DTO of one property, `additionalProperties`,
typed `array<array-key, int>` and never `Optional`: `from(['a' => 1, 'b' => 2])` holds `['a' => 1, 'b' => 2]` and
`from([])` holds `[]`. `toArray()` returns the keys themselves, and `jsonSerialize()` writes the map as a JSON object,
never as `{"additionalProperties": …}`. An open object (`type: object` alone, or `additionalProperties: {}`) is
`array<array-key, mixed>`, with no finding about its values. A map behind a component (`$ref: Counts`) is `CountsDto`,
the same shape, and an inline object value of a root map is named after the property that holds it, as on the request
side (`CreateUser201ResponseAdditionalPropertiesValueDto`). **Beside declared `properties`, an `additionalProperties`
schema is not carried**: the DTO has the declared properties only, and the file's findings say the values of the other
keys are not read. The reasoning is in
[ADR 0013](../../project/decisions/0013-mirror-a-response-schema-or-leave-it-untyped.md).

**Beside a `query` parameter, the values are typed the same way.** The request judges the values of a root map there too
(its guard spares the keys the `query` parameters arrive under, see
[a map's values](./request-validation.md#a-maps-values-are-validated-one-by-one)), so a map of integers beside
`?dryRun=` is `array<array-key, int>`, and a map of `$ref` values holds the value class. The keys of the `query`
parameters are never in the DTO: `validated()` holds both, and `dto()` hands the DTO the body's keys alone. A body key
named like a `query` parameter is read from the query string instead, which the request's findings name. A key a form
delivers under a rewritten name (`a.b` as `a_b`) is carried under that name, and so is it said. A key such as an ID
(`"1042"`) is kept, as the `int` key `1042`, and its value is judged: the
[request writes a rule for each one](./request-validation.md#a-map-or-open-object-body-is-carried-whole).

**A node that recurses is the class of the schema it points back at.** `Address` holds an `Address`: `parent` is typed
`Optional|AddressInputDto`, and `Optional|AddressInputDto|null` when the schema is nullable (`?AddressInputDto` for a
required key, as for any nullable DTO). The request
[validates the recursion down to `x-max-depth`](./request-validation.md#a-recursive-schema-is-validated-to-a-stated-depth)
and refuses what is below, so every level the DTO builds was checked, and the same class describes all of them. The cut
shows in the type, not in a new class: the deepest level a payload may reach has no `parent` (the key is absent, or
`null` when the schema is nullable), and the property says so with `Optional`, or `null`. A list of the schema is
`list<T>` of the same class, and an empty one at the cut passes. A `required` recursion that is not nullable never ends,
so a payload that reaches the cut is the request's 422, and the DTO never sees a missing key. The reasoning for the
depth and the cut is in [ADR 0009](../../project/decisions/0009-cut-recursion-at-a-stated-depth.md).

**A schema in a recursive pair is one class too, whichever operation reaches it first.** `Author` holds `Book`s and
`Book` holds an `Author`: `AuthorInputDto` and `BookInputDto` each name the other, and the two files are the same bytes
whether an operation enters through `Author` or through `Book`. The class of a recursion is the one the schema gets
where it is written, so nothing depends on where the build came in, and the file's findings say so in one wording for
the root, the nested object and the recursion alike (`Class name taken from the schema ...`).

**On a response, a named recursion is its own class at every level, with no depth.** `x-max-depth` limits what a request
validates and a response DTO validates nothing, so `Comment` holding `replies: list<Comment>` is `list<CommentDto>`
however deep a response goes. A recursion the response cannot name stays `mixed`, and the file's findings say why: one
through a schema with no name, one through a schema that is not an object with properties, and a property with
`properties` written beside its reference, which the ancestor's class does not carry.

**A recursion the request did not unroll stays `mixed`, and says so.** The DTO does not build a class from input nothing
checked. Three shapes are left as they arrived:

- **A recursion through a schema with no name.** An inline schema has no position a class could be found by.
- **A field that is two recursion markers at once** (two `allOf` branches that each write the field, pointing back at
  different components).
- **A recursion into a schema that is not an object with properties.** A component written with `properties` and no
  `type` gets no type rule below it, so a string passes the request and a class built from it would be a `TypeError`. An
  array that recurses through its items is a validated list, and the class of an object would read no key of it.

For the first two, the file's findings say that the field points back at a position and that the request validates
nothing below it, so it is `mixed`, and the request's finding says the same thing from its side, where the lever is. For
the third they say what they say of any property of that shape: that it states no type, or that the schema it points
back at is not an object with properties. Either way the wording is the same whichever operation the build meets first.

**One exception to "one class per schema, no `mixed` for a named recursion": a recursive property with `properties`
written beside its reference.** The request does validate it: it unrolls the site with the extra property, level after
level. The class of the ancestor does not carry that property, so a typed field would lose a key the request validated
in `from()` and `toArray()`. The field stays `mixed`, and the file's findings say that the request validates it and that
the class does not carry what is written beside the reference. A `required` or a `type` beside it changes nothing in the
class, and the field is typed. It is the same answer whichever operation the build reaches the site through: the site is
a recursion marker from one side and the expanded schema from the other, and both ask the same question.

**A key the rules cannot name when its object is nested is optional in the DTO.** A required `user.name` is `Optional`
beside the rest of its object, so that a schema's shape does not depend on which operation reached it first, and the
file's findings say so for each such key. So is a name a form client sends under another one that the request cannot
check there (a body that is JSON and a form at once, or two names a form delivers under one key): the property is
`Optional`, and it is optional for every request that reads the schema, whichever got there first. **So is a required
name a form body also nests other names under** (`page` beside `page[size]`, or `filter[a]` beside `filter[a][b]`): the
request judges it only when its value is not an array, and the array of the nested names stands in for it, so the
payload cannot promise `page`. Its value reaches `from()` only when it is not an array, which `dto()` ensures:
`page[size]=3` reaches the DTO as `page[size]` and never as a `page` of `1`. The same holds for the key two names
deliver under one, when a name below it has a rule. In a body that is JSON and a form at once the same holds for a
top-level name: a form's array, or a JSON client's, stands in for a required `page`, and the array is left out of the
data, so `dto()` has nothing to drop. **A name PHP rewrites in a form body that the request does check is not hedged**:
`page[size]` and `a b` are read by `from()` under the contract's name, which the request's `dto()` puts back from the
name PHP delivered, so a required one stays required. A required `user.name` is the exception, and for the reason above
rather than this one: a dot breaks the key list, so it stays optional.

**An enumeration value is written in the docblock only when it is safe to.** A value is data from the specification and
a docblock is code: a string holding the sequence that closes a comment would end it early. Such a value costs the
property its literal union and nothing else.

**A property whose schema states no single type is `mixed`, and says so.** A `oneOf`, a union of two types or a schema
with no `type` never reaches a rule that names one, so the DTO reads the value as it came and the file's findings send
the reader to the request's, which says what was not enforced. On a response there is no request beside the file, so its
findings give the reason themselves. An optional `mixed` is declared `mixed` and written `Optional|mixed` in its
docblock, since PHP refuses `mixed` inside a union.

**An enumeration that is cast keeps its scalar type and no literal union.** `(int) $payload['level']` is an `int` to
Larastan and never `1|2`, so a docblock saying otherwise would be a claim the line beside it contradicts. The cast is
what lets a multipart body's `"1"` into an `int`, and the rule set has already refused every value outside the
enumeration. The allowed values are still written, in words, in the description of the `@param` (`one of 1, 2`), so a
person or a tool reading the class sees the domain at once. A string enumeration is read as it came, so its literal
union is true and stays.

**A request `date-time` arrives in the app timezone.** `from()` keeps the instant and sets the timezone, so
`2026-12-01T19:00:00-05:00` in a UTC app is a `CarbonImmutable` at `2026-12-02T00:00:00+00:00`, and `toArray()` writes
`2026-12-02T00:00:00.000000+00:00`. The reason is Eloquent's `datetime` cast, which keeps the clock digits of a value
and drops its offset: `Event::create($dto->toArray())` would store `2026-12-01 19:00:00` for that value, five hours off,
where it now stores `2026-12-02 00:00:00`. The app timezone is `date_default_timezone_get()`, PHP's default timezone,
which Laravel sets from `config('app.timezone')` at boot. It is the one Eloquent reads stored dates in and `now()` uses.
`toArray()` never converts: it writes the value the property holds, so `$dto->starts_at` and
`$dto->toArray()['starts_at']` always agree. Through the query builder, pass the property
(`insert(['starts_at' => $dto->starts_at])`) rather than the string: Laravel formats a date it binds as a `datetime`
cast does, in the value's own timezone, which is the app's. The string reaches the database as written, and a database
may keep it as text, drop its offset, or refuse it. The client's offset is not on the DTO: what the client sent stays on
`$request->input('starts_at')` and `validated()`. A `query` or path parameter is the string as sent, since none becomes
a `CarbonImmutable`, and a `date` is a calendar day and does not change. One limit stays Eloquent's: in a timezone with
daylight saving time, the hour that repeats in the fall is stored as the same clock time twice. The reasoning is in
[ADR 0025](../../project/decisions/0025-set-a-request-date-time-to-the-app-timezone.md).

**A `date-time` is written back with its fraction.** `toRfc3339String()` drops it, and the request rule set accepts a
fraction of any length (Go sends up to nine digits, .NET seven), so a `toArray()` built on it would lose the sub-second
part of a value on the way to mass assignment. The request side writes `format('Y-m-d\TH:i:s.uP')`, which keeps what
Carbon holds, microseconds. **The response side writes `toJSON()`**, which is what Laravel writes when it serializes a
model: UTC, a `Z` and microseconds, so `2026-11-14T10:00:00.123456-05:00` goes out as `2026-11-14T15:00:00.123456Z`. A
`date` is written with `toDateString()` on both sides, and a `null` stays `null`.

**A response DTO has no property for a `writeOnly` one.** OpenAPI says a response never sends it, so the class would
carry a field nothing fills, and the file's findings name the key that was left out. A `readOnly` property is read like
any other, since a response is where it is sent.

**A `$ref` with keywords beside it is the same DTO as its `allOf` spelling, on a response too.** In a 3.1 document,
`{$ref: User, required: [email]}` and `allOf: [{$ref: User}, {required: [email]}]` give the same properties, types and
`required`. **The order of the properties is the one thing that can differ**: the properties written beside the `$ref`
come before the referenced schema's, where the `allOf` lists its branches in order, so a `$ref` with sibling
`properties` and its `allOf` spelling are two files with the same members in another order. The request side reads them
the same way. In a 3.0 document everything written beside a `$ref` is ignored, and the DTO is the referenced schema's.

**A `null` is checked before anything converts it.** `CarbonImmutable::parse(null)` is the current time and `(int) null`
is `0`, so a nullable property's conversion is wrapped rather than applied:

```php
born_on: $payload['born_on'] === null ? null : CarbonImmutable::parse($payload['born_on']),
```

**An array's items get the conversion their own type would.** `ids[]=1&ids[]=2` arrives as two strings, so a request
DTO's `list<int>` is built with `array_map(intval(...), $payload['ids'])`, which keeps the docblock Larastan trusts true
at run time. On the response side the items go in as they are, like any other scalar, and a list of DTOs is re-indexed
with `array_values()` so that it is a list.

**A file part exists on the request side only**, and `toArray()` hands the `UploadedFile` to mass assignment untouched.
What reaches the column after that is [`uploads.md`](../uploads.md)'s. A schema a generator
[does not honor](../openapi-support.md#schemas) never reaches this table: it has already been reported, by name, before
any class was written.

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

| In `spatie/laravel-data`                                | Here                                                                                             |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| A promoted constructor as the shape                     | **Kept**, as a `final readonly` class                                                            |
| `from()`                                                | **Kept**, from an array only, and written in full by the build                                   |
| `toArray()` and JSON serialization                      | **Kept**, through `Arrayable` and `JsonSerializable` (a response DTO is `JsonSerializable` only) |
| `Optional`                                              | **Kept**, as [a class of this package](#absent-is-optional)                                      |
| Nested data objects and typed collections               | **Kept**, as nested DTOs and a `list<T>` docblock on a plain array                               |
| Casts and transformers                                  | **Kept** as [generated lines](#what-each-schema-type-becomes), never as a registry               |
| Name mapping (`MapInputName`, `MapOutputName`)          | **Kept** only where a key is not a PHP identifier                                                |
| Validation rules inferred from the class                | **Left.** [The generated `FormRequest`](./request-validation.md) owns validation                 |
| `Lazy` properties, includes and excludes                | **Left.** The contract fixes which fields a response carries                                     |
| Wrapping (`wrap('data')`)                               | **Left.** An envelope is a property of the schema, and the DTO already has it                    |
| `Responsable`, `DataCollection`, paginated output       | **Left.** Status belongs to the operation, and paging to [`pagination.md`](../pagination.md)     |
| Magic creation methods (`fromModel()`, `fromRequest()`) | **Left.** [A factory](./response-dtos.md#factories-carry-the-behavior) builds from a model       |
| Computed properties, `additional()`                     | **Left.** A DTO has no behavior, and nothing outside the contract belongs in one                 |
| Attributes, the structure cache                         | **Left.** There is nothing to discover at run time                                               |
| The TypeScript transformer                              | **Left.** [`openapi-typescript`](./publishing.md#types-come-from-openapi-typescript) owns types  |

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
from this package at run time, `Optional`, and does no reflection, reads no configuration and keeps no cache. Everything
that makes `spatie/laravel-data` large sits in the rows the table above leaves behind.

## Why it is not a dependency

**The package does not require `spatie/laravel-data`, and no generated class extends or imports anything from it.** Four
reasons:

1. **A `Data` object cannot be a `readonly` class.** `Data` carries a mutable context of its own, and PHP refuses a
   `readonly` class that extends a class which is not. Each promoted property could still be `readonly`, so immutability
   would survive, but the class keyword would not: it is what guarantees that a property added later is `readonly` too,
   and every other `final readonly` type in this package relies on it.
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
