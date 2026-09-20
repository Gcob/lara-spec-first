---
title: Request Validation
audience: Users
covers: >
    How one operation becomes one `FormRequest`: which parts of the contract the rule set is derived from and which
    parameter locations stay the router's, what a name declared twice does to a build, why `PATCH` is `PUT` with the
    required list emptied and why nothing fills an absent field from a schema `default`, which JSON Schema constraint
    becomes which Laravel rule and what happens to the ones that map to nothing, why nothing rewrites the rule set at
    request time, why the generated request is `final` and where the three things a developer would extend it for
    already live, why the validated payload reaches the controller as a DTO and what an absent field is in it, which
    media types are read and why one operation declares one of them, what an operation with nothing to validate gets,
    and how the request reaches `routeAction` without changing what a custom child may override.
read_before: >
    Implementing the `FormRequest` emitter, or changing what `routeAction` declares.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Request Validation

> **In brief**
>
> - **Not built yet.** Nothing emits a `FormRequest` today; this is the design Phase 2 will follow.
> - One rule set per operation, derived from the request body and the query parameters. The path's own parameters stay
>   the router's.
> - `PATCH` is `PUT` with the required list emptied, and no generated class ever fills an absent field from a schema
>   `default`.
> - A constraint Laravel has no rule for is reported by name, never dropped and never approximated by a looser one.
> - `routeAction` receives the generated request as its first parameter, which is what makes Laravel run it at all, and
>   hands the controller the typed DTO it built.

An operation states what a client may send, and Laravel already has the class that enforces such a statement. This file
owns how one becomes the other: where the rule set comes from, what a constraint with no Laravel equivalent does, and
how the class reaches the controller that needs `$validated`.

> **None of this is behavior yet.** The build emits no `FormRequest`, and no schema is read into anything the package
> keeps. What is written here is the design [Phase 2](../../../README.md#phase-2-the-generated-pipeline) will follow,
> and the card that builds it is [#35](https://github.com/Gcob/lara-spec-first/issues/35). Items marked `Open` are
> undecided, and the number beside one links to the card that settles it.

## One rule set, body and query

**The rule set of an operation is derived from its `requestBody` schema and from its `query` parameters, merged by field
name into one `rules()` array.** One class, one array, one `$validated` for everything downstream to read.

```php
// app/Http/Generated/Requests/CreateUserRequest.php
public function rules(): array
{
    return [
        'email' => ['required', 'string', 'email', 'max:255'],
        'age' => ['sometimes', 'integer', 'min:18'],
        'notify' => ['sometimes', 'boolean'],   // ?notify=1, a query parameter
    ];
}
```

**A parameter's `in` decides whether it reaches the rule set at all**, and three of the four locations do not:

| `in`     | Where its constraints land                                                                                                                   |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `query`  | The rule set, keyed by the parameter's own name, with its own `required` flag deciding presence.                                             |
| `path`   | Nowhere. The router matches the URI, and a value it matched is [a string in the signature](../controllers.md#the-signature-is-the-contract). |
| `header` | Nowhere, and the doctor says so.                                                                                                             |
| `cookie` | Nowhere, and the doctor says so.                                                                                                             |

**A query parameter's presence comes from its own `required` flag, not from the body's `required` list.** They are
different keywords in different places: a Parameter Object carries `required: true` itself, so that parameter gets
`required` and the ones without it get `sometimes`. **And the method does not touch it.**
[`PATCH` empties the body schema's required list](#patch-empties-the-required-list) because a partial update is a
statement about the resource's representation, and a query parameter is not part of that representation: `?notify=1` is
as required on a `PATCH` as it is on a `PUT`.

**A path parameter is the router's question, and its answer is a 404.** `/users/abc` on an operation whose `{id}` is an
integer has not addressed a resource, so refusing it with a 422 field error about a body that was fine is the wrong
answer at the wrong layer. What refuses it instead is route model binding, once
[`x-model`](../controllers.md#x-model-turns-on-the-model-layer) names what `{id}` binds to. Laravel settles the question
anyway: `FormRequest::validationData()` returns `$this->all()`, and route parameters are not in it.

**A header or a cookie parameter is reported and not acted on**, which is an `Ignored`
[support level](../openapi-support.md#support-levels) rather than a gap. Three reasons, the first of them the
specification's own:

1. **A validator produces the wrong status code.** A missing credential is a 401 and an unreadable media type is a 415,
   and neither is something a rule set can answer with. A cookie is the same story one layer over.
2. **OpenAPI ignores three of them itself**, and they are the three a rule would be most likely to meet: a header
   parameter named `Accept`, `Content-Type` or `Authorization` is to be ignored, so a generated rule over one would
   contradict the document it came from. It says nothing about an `X-Tenant-Id`, which is why this reason supports the
   position rather than carrying it.
3. **[`security.md`](../security.md) already owns it.** What an `Authorization` header has to satisfy is settled there,
   and a rule set is not where a second answer to it belongs.

**Three media types are read, and one operation declares one of them.** `application/json`, `multipart/form-data` and
`application/x-www-form-urlencoded` all arrive through `all()`, so one rule set covers all three; what changes is only
whether a part can be a file, which is [`uploads.md`](../uploads.md#one-operation-one-media-type)'s subject. **The rule
set is shared, the values under it are not:** a form-encoded body carries strings only, so `notify=true` meets Laravel's
`boolean` as the string it is where JSON's `true` arrives already typed, and a nested structure arrives flattened. A
contract whose schema leans on a type the encoding cannot carry is served better by `application/json`. **An operation
declaring two of them over two different schemas is a build error**, because one `rules()` cannot hold two rule sets and
[nothing rewrites it per request](#nothing-rewrites-the-rule-set). Two media types over one schema are not a conflict
and produce one rule set.

**Any other media type is reported rather than guessed at.** A body declaring `application/xml` is a contract this
package does not serve, and a `format: byte` string inside a JSON body is
[a string nothing decodes](../uploads.md#a-base64-part-is-a-string).

### A name declared twice is refused

**A field named by both the request body and a query parameter is refused, naming the field and both positions.** Not a
precedence rule, because there is no reading under which one of them is right.

The reason is in the framework rather than in the contract. `Request::all()` is the input source unioned with the query
string, and the input source wins, so a body property and a query parameter of one name arrive as one value with the
query string's copy dropped in silence. One key cannot carry two constraint sets either, so the build would be choosing
which half of the contract to enforce. It refuses instead, the same shape as
[two `x-controller` values reducing to one parent](../controllers.md#the-contract-decides-what-is-customizable).

**Path-item parameters merge with the operation's, and the operation wins on a name they share.** That is OpenAPI's own
rule, not one this package invents, and it is a merge rather than a collision.

### An optional body, all or none

**`requestBody.required: false` means the whole payload may be absent, and never that a required property became
optional.** So the rule set has to accept nothing and refuse half of something, and `required_with` says exactly that:
each field in the required list names the others.

```php
// A requestBody that is not required, whose schema requires street, city and code.
'street' => ['required_with:city,code', 'string'],
'city' => ['required_with:street,code', 'string'],
'code' => ['required_with:street,city', 'string'],
```

**What those three rules do, case by case:**

| The client sends | Outcome                                                                        |
| ---------------- | ------------------------------------------------------------------------------ |
| Nothing          | Valid. No field sees a sibling present, so none of them is required            |
| `street` alone   | Refused. `city` and `code` both see `street`, and both become required         |
| All three        | Validated against the rest of their rules, exactly as a required body would be |

With one required property there is nothing to name, and it becomes `sometimes`. With none, every field was already
`sometimes` and an absent body was already valid.

**The cost is noise, and it is bounded.** Eight required properties mean eight rules naming seven siblings each, in a
file nobody edits by hand. Nothing else in the rule set grows that way, and an optional body that requires properties is
a shape most contracts never write.

**Emptying the rule set at request time was considered and dropped.** A trait overriding `getValidatorInstance()` to
return no rules when `$this->all()` is empty reads better on the page, and it gives up two things:

1. **The query string is in `$this->all()`.** [Query parameters share the rule set](#one-rule-set-body-and-query), so
   `POST /addresses?dry_run=1` with no body stops looking empty, the body's required fields fire, and a request the
   contract allows answers 422. `required_with` asks about the sibling fields instead, which is the question the
   contract asked.
2. **`rules()` stops being the whole answer**, which [nothing is allowed to do here](#nothing-rewrites-the-rule-set),
   whatever the hook it is done through.

## Nothing rewrites the rule set

**`rules()` returns the same array for every request, and no trait, parent class or hook is allowed to change it.** The
generated class carries what the contract produced, and the only thing that moves it is the contract moving and the
build running again.

Three properties rest on that, and each one goes the moment a rule set becomes request-dependent:

1. **The file is the answer.** Open the generated class and you have read what the operation accepts. A trait emptying
   `rules()` when the payload looks empty, or a `withValidator()` hook adding a rule for one case, moves part of the
   contract into behavior nobody reads when they read the rules.
2. **`spec:doctor` compares code against schema with no request in hand.** Every check that reasons about the rule set
   works that way, [the unhonored constraints](#every-constraint-maps-or-reports) and the mass-assignment comparison in
   [`controllers.md`](../controllers.md#writes-by-the-same-default) among them. A rule set that only exists during a
   request is one the doctor cannot check, and a check it cannot run is a promise this package stops keeping.
3. **The build stays idempotent.** [It reproduces its tree byte for byte](./index.md#a-build-never-destroys-your-work),
   which is worth something only while the tree holds the whole behavior.

**Changing the payload stays legitimate; changing the rules does not.**
[`middleware()`](../controllers.md#middleware-is-a-method) runs before the controller's dependencies are resolved, so a
project normalizes what arrives and lets the contract's rules judge it. The difference is which side of the comparison
moves: normalizing input leaves the contract as the authority, rewriting rules makes the code authoritative over it,
which is the one direction [this package does not travel](../../../AGENTS.md).

**And a constraint the contract cannot state is not this class's to carry.** Anything that needs the database, the
authenticated user or another request is a Policy, or a check in the controller after `$validated`. The generated
request is not where a project extends anything, which is [why it is `final`](#the-generated-request-is-final).

## `PATCH` empties the required list

**The schema's `required` list decides what a body must carry, and the HTTP method decides whether that list is read.**
`PUT` reads it as written; `PATCH` treats it as empty. One line of difference in the rule set, and everything else in it
is identical between the two. What it does to the generated type is
[a type of its own](#a-patch-takes-the-partial-type).

**Which is what "`PUT` requires the full body" means here, and it is narrower than it sounds.** It does not mean every
declared property becomes required: a property the schema left out of `required` is optional on a `PUT` too, because the
contract said so, and a generated class that demanded it anyway would be the code contradicting the document. So a field
that is not required carries `sometimes` under either method, and its remaining rules run only when it is sent.

**What each half of a schema becomes under the two methods:**

| Declared            | `PUT`                                                | `PATCH`                   |
| ------------------- | ---------------------------------------------------- | ------------------------- |
| In `required`       | `required`, or `present` if nullable                 | `sometimes`               |
| Not in `required`   | `sometimes`                                          | `sometimes`               |
| Every other keyword | [Mapped as below](#every-constraint-maps-or-reports) | The same rules, unchanged |

`present` rather than `required` for a property that may be null, because Laravel's `required` refuses `null` and a
schema requiring a nullable property is asking for the key, not for a value.

### An absent field gets no default

**Strict `PUT` replaces a resource, and this package does not implement replacement.** The generated request validates
what arrived and nothing else, so `$validated` carries the keys the client sent, and
[`$model->update($validated)`](../controllers.md#writes-by-the-same-default) leaves an absent field at its stored value
rather than returning it to a default. A project needing the other semantics overrides `update()`, which is the seam
`controllers.md` already names.

The generated request is the only class that knows a field was absent rather than empty, so the decision is whether it
carries that knowledge forward. **It does not, and the caveat stands.** Three reasons, in the order they settle it:

1. **There is no value to write.** A schema `default` is an annotation in JSON Schema and not an assertion, and the
   default a column actually has lives in a migration this package never reads. Filling from the schema would produce a
   payload the contract never promised and the database never agreed to.
2. **Every generator downstream assumes `$validated` is what the client sent.** A request that invented a key breaks
   that assumption once, in the one place nothing checks it, so a schema `default` is read by nothing on the request
   side.
3. **A field the client omitted stays visible without being filled.** `$request->missing('name')` answers it, and so
   does [the DTO's third state](#an-absent-field-is-a-third-state), so a project writing replacement semantics has what
   it needs without a generated class inventing a value.

**The generated file says so where it will be read.** A `PUT` whose schema declares optional properties carries that
count as one of [the file's own findings](./generated-file-anatomy.md#every-generated-file-explains-itself), naming
`update()` as the override. A reader opening the class learns the limit; nobody has to come back to this page for it.

## Every constraint maps or reports

**A keyword becomes the Laravel rule that means the same thing, or it becomes a report.** Never a looser rule that
almost matches: accepting a payload the contract refuses is a wrong answer where a report is a missing one, and only the
second is recoverable. Which keywords are honored at all is [the support matrix](../openapi-support.md#schemas)'s to
state; what each honored one becomes is this table's.

**What a type, a nullability and an annotation each become:**

| Schema                                  | Laravel rule                                                                                                            |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `type: string`                          | `string`                                                                                                                |
| `type: integer`                         | `integer`                                                                                                               |
| `type: number`                          | `numeric`                                                                                                               |
| `type: boolean`                         | `boolean`                                                                                                               |
| `type: array`                           | `array` and `list`, with the element rules keyed `field.*`                                                              |
| `type: object`                          | `array`, with each property keyed `field.child`. A JSON object arrives as a PHP array, and Laravel has no `object` rule |
| `nullable`, `type: [..., "null"]`       | `nullable`                                                                                                              |
| `enum`                                  | `Rule::in()` with the declared values                                                                                   |
| `const`                                 | `Rule::in()` with the one value                                                                                         |
| `format: date`                          | `date_format:Y-m-d`                                                                                                     |
| `format: date-time`                     | `date_format:` carrying the RFC 3339 spellings                                                                          |
| `format: email`, `uuid`, `ipv4`, `ipv6` | `email`, `uuid`, `ipv4`, `ipv6`                                                                                         |
| `format: uri`                           | Nothing. Laravel's `url` turns away the non-hierarchical URIs (`urn:…`) JSON Schema allows                              |

**`list` beside `array`, because Laravel's `array` passes for an associative one.** `{"tags": {"a": 1}}` would otherwise
satisfy a `tags` declared `type: array`, which is a payload the contract refuses being accepted. `list` is
`array_is_list()`, which is what a JSON array actually is, and the pair is what keeps that row honest.

**`date_format` rather than `date` on both, because Laravel's `date` accepts whatever `strtotime` accepts**,
`next tuesday` included, which is looser than anything a contract meant to say. The rule takes several formats and
passes on the first that matches, and RFC 3339 needs six of them:

```php
'occurred_at' => ['required', 'string', 'date_format:Y-m-d\TH:i:sp,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vp,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.up,Y-m-d\TH:i:s.uP'],
```

**Six rather than three, and the trap is worth naming because it bites silently.** `date_format` passes only when
`$date->format($pattern)` reproduces the input exactly, and PHP's `p` prints `Z` for a zero offset where `P` prints
`+00:00`. A pattern list built on `p` alone therefore accepts `2026-09-20T14:03:11Z` and **refuses**
`2026-09-20T14:03:11+00:00`, which RFC 3339 allows and which plenty of producers emit. Each precision carries both
spellings: seconds, milliseconds and microseconds, times `p` and `P`.

**The lower-case form is a stated limit rather than a silent one.** RFC 3339 also permits `2026-09-20t14:03:11z`, and no
PHP format character prints a lower-case offset, so covering it would mean literal-only patterns beside all six for a
spelling that is vanishingly rare on the wire. A contract whose producers emit it is one this mapping does not serve,
and saying so here is the difference between a limit and a defect.

Which `format` values are honored at all is [the matrix](../openapi-support.md#any-type)'s row.

**Strings and numbers, where the same two rule names do four jobs:**

| Schema                                 | Laravel rule                                                                                            |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `minLength`, `maxLength`               | `min`, `max` beside `string`, which counts characters                                                   |
| `pattern`                              | `regex:`, [inside the boundary the matrix names](../openapi-support.md#strings-and-numbers)             |
| `minimum`, `maximum`                   | `min`, `max` beside `integer` or `numeric`                                                              |
| `exclusiveMinimum`, `exclusiveMaximum` | `gt:`, `lt:`, read from [the one spelling #34 normalizes to](../openapi-support.md#strings-and-numbers) |
| `multipleOf`                           | `multiple_of:`                                                                                          |

**Arrays and objects, where a rule is keyed rather than named:**

| Schema                              | Laravel rule                                                                                  |
| ----------------------------------- | --------------------------------------------------------------------------------------------- |
| `items`                             | The element rules, under `field.*`                                                            |
| `minItems`, `maxItems`              | `min`, `max` on the array itself                                                              |
| `uniqueItems: true`                 | `distinct:strict` on `field.*`                                                                |
| `properties`                        | One key per property, `field.child`, recursively                                              |
| `additionalProperties: false`       | `array:` on a nested object's field, naming its declared keys                                 |
| `required` under an optional object | `required_with:` naming the parent, since a key cannot be required while its object is absent |
| `dependentRequired`                 | `required_with:` naming the properties that trigger it                                        |

**A nested object is validated as an array, keyed with dots.** One rule key per property, however deep the schema goes,
which is Laravel's own notation for nested input and what `validated()` hands back:

```php
'address' => ['required', 'array:street,city,postal_code'],
'address.street' => ['required_with:address', 'string'],
'address.city' => ['required_with:address', 'string'],
'address.postal_code' => ['sometimes', 'string', 'regex:/^[A-Z]\d[A-Z] ?\d[A-Z]\d$/'],
```

**The dots stop at the validator.** What a controller reads is not that array: the generated request
[hands it a DTO](#the-payload-arrives-as-a-dto) whose properties are the schema's, nested objects included, so
`address.city` is a rule key and `$data->address->city` is how the value is read.

**The payload's root takes an attribute rather than a rule**, because `array:` needs a field to sit on and the root is
not one. Laravel ships `#[FailOnUnknownFields]` for it: every input key the rule set did not name becomes an error,
nested keys included, read from the body rather than from `all()`, which is the right scope for a keyword that talks
about the body's object. Without it an undeclared field is simply absent from `validated()`, so it never reaches a model
either way; what the contract asked for and would not get is the request being refused.

**The attribute measures every depth at once, and that bounds where the build may use it.** A root forbidding unknown
fields over an inner object that allows them would have the inner extras refused too, and a generated class stricter
than the contract is worse than a missing rule: it turns away a payload the document promised to accept. So the build
sets the attribute only when no object in the body's schema permits additional properties, and reports the root's
`additionalProperties: false` as unhonored when one does.

**The attribute does not exist on the lowest Laravel this package supports, and the fallback is named rather than
discovered.** It arrived during the 12.x line while `composer.json` declares `^12.0`, so
[#35](https://github.com/Gcob/lara-spec-first/issues/35) has two honest ways out: an `after()` closure on the generated
class comparing the payload's keys against the rule set's, which is what the attribute does internally and what this
package can write for itself, or the attribute behind a version gate with that closure underneath it anyway. The closure
is the leading answer, because one emitted shape beats two that have to stay equivalent.

**A property name containing a dot is escaped as `\.`**, because Laravel reads an unescaped dot in a rule key as
nesting. `user.name` as a literal property name would otherwise generate rules for a `name` key inside a `user` object
the contract never declared, which is a rule set that is wrong rather than incomplete.

**A constraint that maps to nothing is named per operation by `spec:doctor`**, which is
[#36](https://github.com/Gcob/lara-spec-first/issues/36)'s to build, and by the generated file's own findings for the
reader who is already looking at the class. Neither is a fallback for the other: one runs in CI before anything is
generated, and the other answers "why is this field not enforced" at the moment somebody asks it.

## The generated request is `final`

**One class per operation, `final`, with no abstract half and nothing to extend.** It is the same rule
[the rest of the generated tree follows](../controllers.md#the-contract-decides-what-is-customizable): a name nobody
chose is disposable, and a request's name is always derived. `CreateUserRequest` comes from the operation's
`operationId`, or [from its method and path](./generated-file-anatomy.md#deriving-a-name-without-operationid) when it
has none, in the `Requests` sub-namespace of [the generated tree](./index.md#where-generated-code-lives). There is no
`x-request` to declare it with, and adding one would be inventing a second extension for a class whose every line is
already the contract's.

**The [two-layer split](./index.md#an-interface-and-an-abstract-class) does not apply here either**, for the reason it
stopped applying to [DTOs](./response-dtos.md#the-shape-is-ours-the-behavior-is-yours): that split exists so a human
subclass can be checked against a generated contract surface, and here there is no subclass. Nothing about a rule set is
a seam, because a project that wants different rules is describing a different contract.

**The three things a developer would otherwise extend it for already have homes**, and naming them is what makes `final`
a decision rather than an obstacle:

| Wanted                       | Where it lives                                                                                                             |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Authorization                | The route, and a Policy in the controller. [`security.md`](../security.md#row-level-rules-are-a-policys-job) owns the line |
| Messages and attribute names | `lang/en/validation.php`, which Laravel already reads per field and per rule                                               |
| Normalizing a payload first  | [`middleware()`](../controllers.md#middleware-is-a-method), which runs before the controller's dependencies are resolved   |

**The middleware row is the one worth checking against the framework**, since it is what a `prepareForValidation()`
override would have done. Laravel resolves a controller method's typed parameters after the route's middleware stack has
run, so a middleware calling `$request->merge()` changes what the generated request validates. A payload that needs
trimming, casting or renaming before it can satisfy the contract is normalized there, in a class the project owns.

## The payload arrives as a DTO

**The generated request carries a `data()` method returning a `final readonly` DTO built from the validated payload.**
The rule set says what a client may send; the DTO is that same statement as PHP types, so a controller reads
`$data->email` where it would have read `$validated['email']`, with the shape, the nullability and the autocompletion
the contract already described.

```php
// app/Http/Generated/Requests/CreateUserRequest.php
public function data(): CreateUserInputDto
{
    return CreateUserInputDto::from($this->validated());
}
```

**`validated()` keeps returning Laravel's array.** It is the framework's own method, `validated()` and
`validated('email')` both have callers, and a generated class that changed what they hand back would be this package
redefining something it does not own. The DTO sits beside it, under a name of ours.

**Its properties are the body schema's, and a query parameter is not one of them.** The rule set merges the two, so
`validated()` carries both; the DTO mirrors the body and nothing else, because the body is where its name comes from.
Without that line the naming rule below would not hold: two operations sharing one body `$ref` while declaring different
query parameters would be feeding two different key sets into one shared type. A query parameter stays on the request,
where `validated('page')` reads it and where [pagination](../pagination.md#parameters-must-be-declared) already looks
for it.

**No factory on this side, and the asymmetry is the point.** A
[response DTO factory](./response-dtos.md#factories-carry-the-behavior) exists because a response is built from a model,
and mapping a model onto a response shape is where applications differ. An input DTO is built from the validated
payload, whose keys are the contract's own property names, so there is nothing to teach and nothing to override:
`from()` is a constructor call the build writes in full.

### An absent field is a third state

**Absent is neither `null` nor a default, and the DTO has to carry the difference.** This is what makes an input type
worth generating rather than a convenience: [`PATCH` empties the required list](#patch-empties-the-required-list), so a
DTO whose absent properties arrived as `null` would have `update()` write nulls over stored columns on every partial
update. That is data loss produced by the package and asked for by nobody.

So an optional property carries a third state, and `toArray()` omits what the client did not send. Mass assignment sees
exactly the keys that arrived, which is
[the behavior the array already had](../controllers.md#writes-by-the-same-default), and
[the strict `PUT` caveat](#an-absent-field-gets-no-default) becomes a question the controller can put to the DTO rather
than a fact only the request object held.

**Open ([#38](https://github.com/Gcob/lara-spec-first/issues/38)):** what expresses that third state.
`spatie/laravel-data` ships an `Optional` sentinel for exactly this case, which is an argument for depending on it
rather than only taking its shape; a sentinel of our own is the alternative, and either way it is public API surface
from the first release.

### A `PATCH` takes the partial type

**Nullable and optional are two axes, and the generated type keeps them apart.** `nullable` comes from the schema and
says a value may be `null`. `required`, read under the operation's method, says whether the property may be absent at
all. Conflating the two is what turns a field the contract requires into a type that shrugs about it.

**What each combination becomes**, written here with `spatie/laravel-data`'s spelling for the absent state, which
[#38](https://github.com/Gcob/lara-spec-first/issues/38) settles:

| The schema says                 | `POST` and `PUT`               | `PATCH`                        |
| ------------------------------- | ------------------------------ | ------------------------------ |
| In `required`, not nullable     | `string $name`                 | `Optional\|string $name`       |
| In `required`, nullable         | `?string $name`                | `Optional\|string\|null $name` |
| Not in `required`, not nullable | `Optional\|string $name`       | `Optional\|string $name`       |
| Not in `required`, nullable     | `Optional\|string\|null $name` | `Optional\|string\|null $name` |

**Only the first two rows differ, and they are the whole reason a `PATCH` gets its own type.** It validates against
[a different rule set](#patch-empties-the-required-list), so a type shared with the `PUT` would have to be the
permissive one, and a developer writing a create would unwrap an `Optional` on every field the contract requires. The
document said the field is there. The generated type has no business saying it might not be.

**The partial variant carries the same name with a `Partial` marker, and both types are generated for every request
body, even when they are identical today.** A schema with an empty `required` list gets a full type and a partial type
with the same properties, and a shape no `PATCH` currently reads gets its partial type anyway.

**Predictability is the reason, and it is the one that already has
[the routes file written when a contract has nothing to route](./index.md#the-routes-are-one-file).** A class that
exists only under a condition is a class a developer has to check for before importing, and the condition here is one
entry in one list: adding a `required` property would bring a type into existence, removing the last one would delete a
name somebody had already imported, and neither edit looks like it touches a class. Generating both costs build output
nobody edits, in a tree [a build reproduces byte for byte](./index.md#a-build-never-destroys-your-work). A partial type
no operation reads says so in [its own findings](./generated-file-anatomy.md#every-generated-file-explains-itself), the
way every generated file explains what the build worked out about it.

Reuse is unchanged: operations pointing at one `$ref` share its two types, and
[an optional body](#an-optional-body-all-or-none) takes the partial one whatever its method, since there every property
may be absent.

### Two directions, two types

**A request DTO and a response DTO stay separate classes even when one schema produced both**, and the contract is what
separates them: a `readOnly` property is forbidden in a request body and expected in a response, and an optional
property is optional for different reasons on each side. One class serving both would have to be the union of two
shapes, which is a type describing neither.

**The name comes from the schema when the schema has one.** A body written as `$ref: '#/components/schemas/NewUser'`
takes that name, so every operation sending that shape shares one type. An inline body takes the operation's name with
an `Input` marker, which is what keeps it from colliding with the response DTO derived from the same operation. The
exact spellings are public API surface under [rule 4](../openapi-support.md#the-four-rules) and belong to
[the name freeze](../../../README.md#before-10-freeze-what-a-major-would-cost).

## Nothing to validate, no class

**No `requestBody`, no `query` parameter, no generated class, and no input DTO beside it.** `DELETE /users/{id}` gets
nothing, and the build says so rather than emitting a class whose `rules()` returns an empty array. An empty rule set
enforces nothing, and a file that exists to enforce nothing is a file telling its reader something untrue, which is the
same reasoning [the routes file's own findings](./index.md#the-routes-are-one-file) follow when a contract has nothing
to route.

**Adding a body to that operation later changes `routeAction`'s signature, and every custom child stops compiling until
it follows.** That is [the split doing its job](./index.md#the-split-makes-a-change-loud) rather than a defect: the
contract gained a statement about what a client may send, and a custom controller that never heard about it is exactly
what a static analysis error should be reporting.

## The type hint is what runs it

**The generated request is `routeAction`'s first parameter, ahead of the path's own**, on the generated parent and on
every child that overrides it:

```php
// app/Http/Generated/Controllers/UpdateUserController.php
public function routeAction(UpdateUserRequest $request, string $id): UserDto
{
    return $this->update($request->data());
}
```

**Without that type hint nothing validates anything.** Laravel runs a `FormRequest` because a method declared one, so a
generated class nobody declares is emitted, correct, tested, and never executed. That is the failure this section exists
to prevent, and it is the reason the parameter is in the signature rather than resolved from the container inside the
method body: `app(UpdateUserRequest::class)` validates too, and it hides the one thing a reader of the method needs to
see.

**First, because that is where every Laravel codebase puts it.** Laravel splices a class-typed parameter in from the
container and fills the rest from the route's own parameters, so either order works and only one of them reads like
ordinary Laravel.

**What it does to the seam is the whole cost, and it is a shape change rather than an addition.**
[`ControllerEmitter::signature()`](https://github.com/Gcob/lara-spec-first/blob/main/src/Generation/ControllerEmitter.php)
builds that signature from the path's parameters alone today, and a child controller has to match whatever the parent
declares. So the request parameter is part of the two-class seam rather than something beside it, and
[#35](https://github.com/Gcob/lara-spec-first/issues/35) is where the emitter learns it.

Two alternatives were considered and dropped:

1. **Validating in a middleware.** The rule set would reach the request as an array, `$validated` would come off a plain
   `Request` with no type behind it, and static analysis and IDE navigation would both lose the class. It also moves the
   contract's rules into a place the doctor cannot point at per operation.
2. **Validating in `SpecController`.** A shared base cannot carry per-operation knowledge, which is
   [already why it stays thin](../controllers.md#what-the-generated-controller-contains), and the only way to give it
   any is to have it read something at request time. Nothing in this package
   [opens a specification while the application runs](./index.md#the-runtime-never-sees-the-spec).

## What this document does not cover

Four things a reader arrives here wanting, each owned elsewhere:

1. **Whether a keyword is honored at all.** This page maps the honored ones onto Laravel rules; the level each keyword
   carries, and the exit code that follows from it, are [`openapi-support.md`](../openapi-support.md#schemas)'s.
2. **What the validated payload is then used for.** The DTO reaches a CRUD method and a model from
   [`controllers.md`](../controllers.md#writes-by-the-same-default), and whether the model will accept the fields in it
   is the doctor's mass-assignment check.
3. **Everything a file part changes.** The rules it becomes, the disk nothing stores it on until a project names one,
   and the trait that decides what lands in the column are [`uploads.md`](../uploads.md)'s.
4. **The shape of what comes back.** A response schema becomes a type through [`response-dtos.md`](./response-dtos.md),
   and nothing on this page describes output.
