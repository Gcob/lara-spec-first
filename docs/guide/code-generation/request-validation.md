---
title: Request Validation
audience: Users
covers: >
    How one operation becomes one `FormRequest`: which parts of the contract the rule set is derived from and which
    parameter locations stay the router's, what a name declared twice does to a build, why `PATCH` is `PUT` with the
    required list emptied and why nothing fills an absent field from a schema `default`, which JSON Schema constraint
    becomes which Laravel rule and what happens to the ones that map to nothing, why nothing rewrites the rule set at
    request time, why the generated request is `final` and where the three things a developer would extend it for
    already live, why the validated payload reaches the controller as a DTO, what an absent field is in it and how a map
    or open-object body is carried whole, where each field is read from (the body for a body field, the query string for
    a declared `query` parameter), what a client gets when its request is refused and how an application makes every
    refusal a JSON `422`, how deep a recursive schema is validated and what a deeper payload gets, which media types are
    read and why one operation declares one of them, what an operation with nothing to validate gets, and how the
    request reaches `routeAction` without changing what a custom child may override.
read_before: >
    Implementing the `FormRequest` emitter, or changing what `routeAction` declares.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Request Validation

> **In brief**
>
> - **Built.** `spec:build` emits one `FormRequest` per operation with something to validate, and the whole of
>   [the mapping table](#every-constraint-maps-or-reports) is applied: the scalar types, nullability and presence, the
>   bounds, `format`, `enum` and `pattern`, arrays and nested objects, `allOf`, `dependentRequired`,
>   `additionalProperties: false`, an optional body's "all or none", and the file parts of a multipart body. Whatever no
>   Laravel rule means the same as is named in the generated file's own findings rather than enforced, see
>   [what is built](#what-is-built-today).
> - One rule set per operation, derived from the request body and the query parameters. The path's own parameters stay
>   the router's.
> - **Only a client that asks for JSON gets the `422`** (`Accept: application/json`, or an XHR request). Any other
>   client gets a redirect back, and its errors are lost. An application that wants a JSON `422` for every refusal says
>   so in [its exception handling](#what-a-client-gets-when-a-request-is-refused).
> - A body field is read from the body and a `query` parameter from the query string, never from each other: the request
>   overrides `validationData()` so the URL cannot satisfy a body field, nor the body a query parameter.
> - `PATCH` is `PUT` with the required list emptied, and no generated class ever fills an absent field from a schema
>   `default`.
> - A request whose body is an object has `dto()`, which returns the body as
>   [a generated input DTO](#the-payload-arrives-as-a-dto) built from `validated()` (or `null` for a `null` body), and
>   `validated()` itself keeps returning Laravel's array.
> - A recursive schema is validated down to the depth it states with `x-max-depth` (three when it says nothing), and a
>   payload deeper than that is refused, with a finding in the generated file that names the extension. Unrolling is
>   capped at 1,000 rule keys per operation: a deeper branching schema is cut at one lower level on every branch, with a
>   finding, so it never costs a build.
> - A constraint Laravel has no rule for is reported by name, never dropped and never approximated by a looser one. A
>   rule is never stricter than the contract either, unless matching it exactly would be far too complex in Laravel, and
>   then the generated file says so in a finding.
>   [The reasoning, and the places the code falls short](../../project/decisions/0006-hold-rules-to-the-contract.md), is
>   an ADR.
> - `routeAction` receives the generated request as its first parameter, which is what makes Laravel run it at all, and
>   hands the controller the typed DTO it built.

An operation states what a client may send, and Laravel already has the class that enforces such a statement. This file
owns how one becomes the other: where the rule set comes from, what a constraint with no Laravel equivalent does, and
how the class reaches the controller that needs `$validated`.

> **Part of this is behavior and part of it is design**, and the section below says which is which. Items marked `Open`
> are undecided, and the number beside one links to the card that settles it.

## What is built today

`spec:build` emits one `final` `FormRequest` per operation that states anything about its input, into the `Requests`
sub-namespace of the generated tree, and declares it as `routeAction`'s first parameter. What a rule set contains today:

| Built                                                                                                         | Reported and not enforced                                                                                               |
| ------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Every row of [the mapping table](#every-constraint-maps-or-reports): types, bounds, `format`, `enum`, arrays  | A `format` no Laravel rule means the same as, `uri` the most common                                                     |
| `required` for an integer, a number or a boolean, `present` for every other type, `sometimes`                 | A `pattern` across [the ECMA-262 / PCRE boundary](../openapi-support.md#strings-and-numbers)                            |
| [`PATCH` reading the required list as empty](#patch-empties-the-required-list)                                | An `encoding` `contentType` no rule can hold: a text type, or a part that is not a file ([`uploads.md`](../uploads.md)) |
| [An optional body's "all or none"](#an-optional-body-all-or-none), and a nested object's required children    | A field name carrying `*`, which Laravel reads as a wildcard and offers no escape for                                   |
| `allOf` merged, `dependentRequired`, `additionalProperties: false` at every depth                             | A `query` name PHP appends to (`ids[]`) or delivers under no name, or under a key the body arrives under                |
| A recursive schema, [down to the depth it states](#a-recursive-schema-is-validated-to-a-stated-depth)         | A form client's value for a top-level name PHP rewrites, in a body that is JSON too                                     |
| [A map's values](#a-maps-values-are-validated-one-by-one), each by its `additionalProperties` schema          | A map's values beside a declared name the guard cannot spare                                                            |
| A form name beside the names a form nests under it, [judged by shape](#one-rule-set-body-and-query)           | Two top-level names a form delivers under one key, which no rule can tell apart                                         |
|                                                                                                               | A JSON client's array under a name a body that is JSON and a form judges by shape (`{"page": [1]}`)                     |
|                                                                                                               | A bound on a field that states no single type                                                                           |
| A `query` parameter, with its own `required`, and a name PHP rewrites keyed by what it delivers (`page.size`) | A key of a map body that a `query` parameter shadows, or that a form delivers under a rewritten name                    |
| A name in `required` that `properties` does not declare: `present`, and the map's value rule where it has one |                                                                                                                         |
| [A file part](../uploads.md#the-schema-names-the-file-part) of a multipart body: `file`, `mimetypes`, a size  |                                                                                                                         |
| [A map or open-object body](#a-map-or-open-object-body-is-carried-whole), every key in `validated()`          |                                                                                                                         |

**A body declared only in media types this package does not read gets no class**, the same as an operation stating
nothing, and `spec:build` warns about it on a line of its own: unlike that operation, this one states something nobody
validates.

**Everything in the right-hand column is named, per field, in the generated file's own `Findings` block**, which is the
half that makes an incomplete rule set honest rather than misleading. A stricter rule is a named exception, a looser one
is a gap listed in [the ADR](../../project/decisions/0006-hold-rules-to-the-contract.md), and nothing is dropped in
silence: that is [the principle](#every-constraint-maps-or-reports) the rest of this page is about, and it is what makes
each pass safe to ship on its own.

**The input DTO is built.** `spec:build` writes [the DTO](#the-payload-arrives-as-a-dto) of every body that is an
object, in the `Data` sub-namespace, and the request returns it from `dto()`. A request whose operation has no body, or
whose body is not an object, has no `dto()`: its `query` parameters are read with `validated('name')`, and one whose
name PHP rewrites with the key PHP delivers it under (`validated('page.size')`).

## What a client gets when a request is refused

**A generated request is a Laravel `FormRequest`, so a refusal is answered the way Laravel answers one, and this package
changes nothing about it.** What the client gets depends on what it asks for:

| The client                                                                              | What a failed validation answers                                   |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Asks for JSON: `Accept: application/json` (or `application/problem+json`) comes first   | `422`, and the errors as JSON                                      |
| An XHR request: `X-Requested-With: XMLHttpRequest`, and an `Accept` that takes any type | `422`, and the errors as JSON                                      |
| Any other: a `curl` with no `Accept`, an HTML form, a browser, an HTTP client's default | A `302` redirect back to the previous URL, and the errors are lost |

**Only a client asking for JSON gets the `422`.** A client that sends `*/*` or nothing is redirected, and a redirect
back from a request with no previous page lands on the root. In a test, `postJson()` gets the `422` and `post()` gets
the `302`.

**The redirect carries no errors, because the generated routes run no session middleware.** Laravel flashes the errors
into the session, and nothing on these routes starts or saves one, so the client lands on the previous page with nothing
to show. An HTML form that needs its errors back has to run on a route that starts the session, which is the
application's to arrange.

**An API usually wants the `422` for everybody, and that is the application's to decide**, since the generated request
is [`final`](#the-generated-request-is-final) and nothing generated carries a global setting. Laravel has two ways, both
its own:

1. **Render every exception as JSON on the API routes**, with
   [`shouldRenderJsonWhen`](https://laravel.com/docs/12.x/errors#rendering-exceptions) in `bootstrap/app.php`. A
   validation failure then answers a `422` with JSON whatever the client sent, and so does every other exception on
   those routes:

    ```php
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ```

2. **Make every request on a route group ask for JSON**, with a middleware that sets `Accept: application/json` before
   the request is validated, applied to the group the generated routes live in. Laravel's
   [form request documentation](https://laravel.com/docs/12.x/validation#form-request-validation) describes what the
   client then gets.

**The package registers neither**, because the generated routes are loaded into an application that may serve pages and
an API from one tree, and a global JSON setting is not a decision a generator can take for it.

## One rule set, body and query

**The rule set of an operation is derived from its `requestBody` schema and from its `query` parameters, merged by field
name into one `rules()` array.** One class, one array, one `$validated` for everything downstream to read.

```php
// app/Http/Generated/Requests/CreateUserRequest.php
public function rules(): array
{
    return [
        'email' => ['present', 'string', 'email', 'max:255'],
        'age' => ['sometimes', 'integer:strict', 'min:18'],   // a JSON body keeps its types
        'notify' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],   // ?notify=true, a query parameter
    ];
}
```

**A parameter's `in` decides whether it reaches the rule set at all**, and three of the four locations do not:

| `in`     | Where its constraints land                                                                                                                                    |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `query`  | The rule set, keyed by the parameter's own name (by the key PHP delivers it under when it rewrites the name), with its own `required` flag deciding presence. |
| `path`   | Nowhere. The router matches the URI, and a value it matched is [a string in the signature](../controllers.md#the-signature-is-the-contract).                  |
| `header` | Nowhere, and the doctor says so.                                                                                                                              |
| `cookie` | Nowhere, and the doctor says so.                                                                                                                              |

**A query parameter's presence comes from its own `required` flag, not from the body's `required` list.** They are
different keywords in different places: a Parameter Object carries `required: true` itself, so that parameter asks for
its key, with [the same presence rule](#patch-empties-the-required-list) a body property gets, and the ones without it
get `sometimes`. **And the method does not touch it.**
[`PATCH` empties the body schema's required list](#patch-empties-the-required-list) because a partial update is a
statement about the resource's representation, and a query parameter is not part of that representation: `?notify=1` is
as required on a `PATCH` as it is on a `PUT`.

**An array query parameter gets its presence rule and nothing else, and an object query parameter gets nothing.** A
query string is not JSON: OpenAPI's default serialization of an array is `?tag=a&tag=b`, of which PHP keeps only the
last value, and `explode: false` sends `?tag=a,b` as one string. `style` and `explode` are not read, so the value
reaching the validator is not the shape the schema describes, and every rule about that shape would refuse a valid
request. An object is worse off still: the default style sends one key per property, `?status=a`, and never the
parameter's own name, so even its presence would refuse a valid request, and it is `sometimes` whatever its `required`
flag. The generated file says so in both cases.

**A query name PHP rewrites is validated under the key PHP delivers.** PHP parses the query string before Laravel sees
it: a `.` or a space in a name becomes `_`, so `user.name` arrives as `user_name`, and `[` opens a nested array, so
`page[size]` arrives as `page => [size => 3]`. The value is there, under that key, so the rule is keyed by it:
`page.size`, `user_name`, `a_b`, and `filter.created.gte` for `filter[created][gte]`. The key is asked of PHP itself
(`parse_str()`, the parser the query bag is built from), as a form body's names are, and the name the contract writes
stays the label of every finding. The rule is the one the parameter's type would get anywhere else: for a required
integer with a `minimum`, `?page[size]=abc` and `?page[size]=0` answer 422 on `page.size`, and a request that leaves
`page[size]` out answers 422 too. `validated()` holds the value nested and as the string a query string sends:
`['page' => ['size' => '3']]`. **`QUERY_KEYS` holds the key each parameter arrives under**, `page` for `page[size]` and
`page[number]`, once.

**There is no rule on the head, because a query string is not closed.** An undeclared key is ignored on any `query`
parameter, so `page[evil]=1` beside an optional `page[size]`, or `page=abc`, is an undeclared key that passes and that
`validated()` does not carry. A body read only as a form closes each level of its bracketed names, but a query string is
open the way the contract's other `query` parameters are.

**Some names keep a finding and get no rule**, and the doctor still reports them. A name PHP appends to (`ids[]`,
`a[][b]`, `a[]b`) arrives at the next free index of a list, so no fixed key holds the value, and a name PHP delivers
under no name (`[x]`) has no key at all. A name with a `*` is
[a field name carrying `*`](#every-constraint-maps-or-reports): Laravel reads it as a wildcard and offers no escape. **A
rewritten name whose head is a key the body also arrives under keeps its finding too**: a JSON body with a property
`page` and a `query` parameter `page[size]`, a form body with `page[number]` and the same parameter, a JSON property
`user_name` and a parameter `user.name`. One key holds both values, so a rule for the query's would judge the body's. It
is a finding and not a refusal, as it is for [a name declared twice](#a-name-declared-twice-is-refused): no rule is
emitted for it, so the build is not choosing which half of the contract to enforce, and every contract that built before
still builds.

**A plain query name keeps its rules beside the names PHP nests under it.** `page` beside `page[size]`, `ids` beside
`ids[]`, `filter[a]` beside `filter[a][b]`: `?page[size]=3` arrives as `page => [size => 3]`, so a rule for the plain
`page` would judge that array and refuse a request the contract allows. The plain name's rules are therefore wrapped in
Laravel's own `Rule::when()` and judge the value only when it is not an array, and each nested name keeps its rules:

```php
'page' => Rule::when(
    fn (Fluent $input): bool => ! is_array($input->get('page')),
    ['sometimes', 'integer', 'min:1'],
    ['array'],
),
'page.size' => ['sometimes', 'integer'],
```

`?page=abc` and `?page=0` answer 422 on `page`, `?page=2` passes and `validated('page')` is `'2'`, `?page[size]=3`
passes, and `?page[size]=abc` answers 422 on `page.size`. `page` stays in `QUERY_KEYS`. The third argument, `['array']`,
is for a plain name with a rule below it: Laravel keeps an array's unchecked content out of `validated()` only then.
With no rule below (`ids` beside `ids[]`, `page` beside `page[*]`) it is `['exclude']`, so `?ids[]=-5&ids[]=abc` passes
without an `ids` in `validated()`, instead of an array nobody checked. There is no finding, since the name has its
rules, and `spec:doctor` reports nothing for it. The one thing the shape check cannot tell is a `page` the client left
out from the nested names it sent instead: the array stands in for it, so a required `page` is not asked for when
`page[size]` is sent. The comparison is step by step and never by string, so `filter[a]` is no ancestor of
`filter[ab][c]`, and a form body does the same with its own names (below). The reasoning is in
[ADR 0002](../../project/decisions/0002-judge-plain-query-names-by-shape.md).

**Two query names PHP delivers under one key get no rule, and one finding names them.** `user.name` and `user_name`,
`a.b` and `a b` arrive as the same key, so the value of one is the value of the other and no rule can tell them apart:
the same goes for `ids[1]` beside `ids[]`, since PHP appends at any index. Neither name has a rule, a finding names each
pair, the doctor still reports what each states (`required`, a bound), and neither reaches `QUERY_KEYS`. A plain name
beside a body key is still [refused](#a-name-declared-twice-is-refused), before it is given no rule.

**A top-level body name PHP rewrites is keyed by the name PHP delivers, when the body is read only as a form.** A
form-encoded or multipart body goes through the same parser as the query string: `user.name=ada` arrives as `user_name`,
`a b` as `a_b`, and `page[size]=3` as `page => [size => 3]`. Measured with a real SAPI (`curl -d` and `curl -F` to
`php -S`, PHP 8.5), a file part included (`my.file` arrives as `my_file`), and only the top-level name: `address[a.b]`
keeps its dot, and a JSON body keeps every name as written. Unlike a query name, the contract's name for a body field
has a delivered name a rule can find, so the rules are keyed by it: `user_name` and `page.size` carry the presence, the
type and the bounds of `user.name` and `page[size]`, and the name takes part in an optional body's `required_with` and
in `dependentRequired` under the key it arrives as. **The generated `dto()` puts the value back under the contract's
name** before `from()` reads it, from a `FORM_NAMES` constant in the request, so a valid value reaches the DTO and the
DTO's properties stay the contract's, one class serving a JSON body and a nested use as well. `validated()` is left as
Laravel returns it, with `user_name`. **A closed body accepts the name PHP gives the key**, and beside it the one the
contract writes when the body is also JSON. For a bracketed name that key is the head (`page` for `page[size]`), and a
body read only as a form closes the head with its own rule, `'page' => ['sometimes', 'array:size']`, so `page[evil]=1`
and `page=abc` are refused as the contract refuses them. A name with more brackets closes every level above its leaf:
`filter[created][gte]` beside `filter[x]` gives `'filter' => ['sometimes', 'array:created,x']` and
`'filter.created' => ['sometimes', 'array:gte']`, so `filter[evil]=1`, `filter=abc` and `filter[created][lt]=5` are
refused. Only two kinds of level stay open, and the generated file says the key check accepts them: one whose key
`array:` cannot name (a comma in it), and the key a declared name is delivered at when that name keeps no shape check
(one typed `array` or `object`, or one in a collision, below), because a value can sit there and `array:` would refuse
it. A name that is judged by shape closes its level in its own array branch (below). For a name with no bracket
(`user.name`), nothing tells `user_name` apart from it: the key check accepts a `user_name` the contract does not
declare, and the generated file says so, as it does for a bracketed name in a body that is JSON too, whose head is not
closed. A name that collides with another (below) is already named by the collision finding, and when the key it arrives
under is one the contract declares, the closed body says nothing more about it. A bracketed name below another closes
the levels above it like any other: `filter[a]`, `filter[a][b]` and `filter[c]` give
`'filter' => ['sometimes', 'array:a,c']`, so `filter[evil]=1` is refused, and `filter[a]` closes `filter.a` in its own
array branch (`['array:b']`), so `filter[a][evil]=1` is refused too. Two names a form delivers under one bracketed key
(`a.b[c]` beside `a_b[c]`, both `a_b.c`) close `a_b` to `c`.

**A name a form body declares beside the names a form nests under it is judged by shape, as a query name is.** `page`
beside `page[size]`, `filter[a]` beside `filter[a][b]`, at any depth, and `ids` beside `ids[]`, which a form reads as
`ids.0` (it has no append notion). A form delivers `page[size]=3` as `page => [size => 3]`, so a rule for the plain
`page` would judge that array and refuse a request the contract allows. The name's rules are wrapped in `Rule::when()`
and judge the value only when it is not an array, and each nested name keeps its own, keyed by the path PHP delivers it
under:

```php
'page' => Rule::when(
    fn (Fluent $input): bool => ! is_array($input->get('page')),
    ['sometimes', 'integer', 'min:1'],
    ['array'],
),
'page.size' => ['sometimes', 'integer'],
```

`page=abc` and `page[size]=abc` answer 422, `page=2` and `page[size]=3` pass, and no finding is written: both names have
their rules. The array branch is `['array']` when a name below has a rule, and `['exclude']` when none has (`page`
beside `page[*]` in an open body, or `a_b` beside `a.b[c]` and `a_b[c]`, which collide), so Laravel does not keep an
array nobody checked in `validated()`. **In a closed body the array branch holds the keys the contract declares below
the name:** `page` beside `page[size]` gives `['array:size']`, so `page[evil]=1` and `page[size]=3&page[evil]=1` answer
422, as the contract refuses them, while `page=2` and `page[size]=3` still pass. A branch that drops the array holds the
keys too, in front of the `exclude` (`['array:b', 'exclude']` for `filter[a]` beside an object `filter[a][b]` that has a
name below it, so both are withheld), so `filter[a][evil]=1` is refused there too, where that level stayed open. A key
`array:` cannot name keeps `['array']`, and the generated file says the key check accepts it: a comma in a key, and the
key of a `*` name (`page[*]` beside `page[size]`), which Laravel hides behind a placeholder, so `array:size,*` would
refuse the `page[*]=5` the contract declares. That level accepts `page[evil]=1` too. A comparison is step by step, so
`filter[a]` is no ancestor of `filter[ab][c]`. **`dto()` reads the name only when it is not an array:** the request
lists such names (not those whose array branch is `exclude`, which `validated()` never holds) in a `SCALAR_NAMES`
constant, and `contractNames()` drops the array once it has read each nested name out of it, because `from()` casts what
it reads and `(int)` of an array is `1`. So `page[size]=3` reaches the DTO as `page[size]` and `page` is `Optional`,
which is what a required `page` is there: the array stands in for it, and the file says so. The key that two names
deliver under one is in the list too, when a name below it has a rule. The reasoning is in
[ADR 0012](../../project/decisions/0012-judge-plain-form-names-by-shape.md).

**The shape check cannot tell a `page` the client left out from the names it sent instead, when the contract asks for
`page`.** A required `page`, a `page` that a `dependentRequired` makes required, and a required `page` of an optional
body (its [all or none](#an-optional-body-all-or-none)) are satisfied by the array of `page[size]`: `page[size]=3` alone
passes. It is the blind spot the query string has, and nothing is refused that the contract allows. Two cases go the
other way, and the generated file says the request is stricter than the contract: a `dependentRequired` that `page`
triggers (`page` makes `other` required, and `page[size]=3` alone delivers an array under `page`, which counts as `page`
sent, so `other` is asked for), and a `page` the contract asks for beside only `page[*]`, or any `page` beside only
`page[*]` in a closed body. A `*` name has no rule, so it can shape an upper name only when nothing asks for it and the
body is open: an optional `page` beside `page[*]` passes `page[*]=5`, and `page` is left out of `validated()`. A closed
body would then have a level it cannot close, since its key check cannot name the `*` key, so `page[evil]=5` would pass
where the contract refuses it: `page` keeps its rule as it was, which refuses every array under it, `page[*]=5`
included, and the file says so. A `page` the contract asks for keeps its rule as it was, since shaping it would let
`page[*]=5` alone satisfy it, and a valid `page=2&page[*]=5` is refused (PHP keeps the later name, so `page` arrives as
an array). **A `PATCH` asks for no presence through `required`,** so a required `page` there binds none and is shaped
like an optional one: `page[*]=5` and `page=2&page[*]=5` pass, with `page` left out of `validated()`. A
`dependentRequired` still asks for presence in a `PATCH`, so a dependent `page` keeps its rule there.

**Two names a form delivers under one key get no rule, and one finding names them.** `user.name` and `user_name` arrive
as one key, so the value of one is the value of the other and no rule can tell them apart. Neither gets a rule, none
takes part in `required_with` or `dependentRequired` (a `dependentRequired` it triggers says which dependency it loses),
and their DTO properties are `Optional`. A name below them (`user_name[x]`) keeps its own rule. Two names that differ
only by a character PHP rewrites also derive one PHP property name, so the DTO refuses them with its own error before
this finding is reached. **A name typed `array` or `object` beside a name below it keeps no rule either:** its own value
may be an array, so no rule can tell its value from the array a form builds, and `Rule::when()` would never run its
rules. A name PHP rewrites above a name below it keeps no rule when the body is JSON as well: a JSON client sends it
under one key and a form client under another, so no one shape check holds both, and the finding says so. A name PHP
leaves alone is judged by shape in a body that is JSON as well (below).

**A body that is JSON and a form judges a top-level name by shape too.** `page` beside `page[size]` keeps its rules
behind `Rule::when()`, and they judge every scalar from either client: `page=abc` from a form and `{"page": "abc"}` from
JSON answer 422. `page[size]` keeps its JSON rule under the literal key `page[size]` (Laravel reads `[` in a rule key as
a plain character), which a form client's `page => [size => ...]` never reaches, so what a form client sends there is
still not checked, and the finding for a name PHP rewrites says so. The array branch is `['exclude']`: no ruled name
sits below `page` in the data, since the nested name keeps its literal key, and a `SCALAR_NAMES` list is not written,
since an array never reaches `validated()`. A required `page` is asked for from either client when nothing is sent, and
for a body that may be absent, a JSON `{}` is refused on `page` through the
[required-keys check](#an-optional-body-all-or-none). The blind spot of a form body holds here too: a form's
`page[size]=3` alone, or a JSON array under `page`, satisfies a presence the contract asks for, and a
`dependentRequired` that `page` triggers is stricter than the contract for the same reason, with its finding. **One gap,
which the generated file states: a JSON client's array under `page` (`{"page": [1]}`) passes and is left out of
`validated()`,** where the contract refuses it. One rule set serves both clients and `rules()` does not branch on the
media type, so a rule that refused the array would refuse the array a form builds for `page[size]=3`. The build counts
it as a constraint it does not enforce. Below the top level (`filter[a]` beside `filter[a][b]`), a body that is JSON as
well keeps both names as written, with their own type rules, since Laravel reads `[` as a plain character.

**A body that is JSON and a form at once keeps the rule for the name the contract writes, and a form client's value is
not checked.** A JSON client sends `user.name` exactly, and a rule keyed by it is right for that client. A form client
sends it as `user.name` too, which PHP delivers as `user_name`, and no one rule set holds both keys without refusing one
of the two clients. The value a form client sends is not checked, and **a required name is not asked for, from either
client**: a presence rule keyed by the JSON name would answer 422 to every form client, which is stricter than the
contract and the one thing the principle forbids here without a reason. The same rule set serves the JSON client, and
`rules()` does not branch on the media type, so a JSON body that leaves the name out is accepted, which the contract
refuses. The generated file says that, and where a form client's value arrives and that it is not checked there, and the
matching DTO property is `Optional`: the payload cannot be promised to carry it. This is the one report path for a name
PHP rewrites; a contract that needs it checked is better served by one media type.

**A path parameter is the router's question, and its answer is a 404.** `/users/abc` on an operation whose `{id}` is an
integer has not addressed a resource, so refusing it with a 422 field error about a body that was fine is the wrong
answer at the wrong layer. What refuses it instead is route model binding, once
[`x-model`](../controllers.md#x-model-turns-on-the-model-layer) names what `{id}` binds to. Laravel settles the question
anyway: the data a request validates has never held route parameters, and
[the generated one](#each-field-is-read-from-where-the-contract-put-it) does not either.

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
`application/x-www-form-urlencoded` are all read from the body, so one rule set covers all three; what changes is only
whether a part can be a file, which is [`uploads.md`](../uploads.md#one-operation-one-media-type)'s subject. **The rule
set is shared, the values under it are not:** a form-encoded body carries strings only, so `notify=true` arrives as the
string it is where JSON's `true` arrives already typed, which [a boolean's rule](#every-constraint-maps-or-reports)
reads, and a nested structure arrives flattened. A contract whose schema leans on a type the encoding cannot carry is
served better by `application/json`. **An operation declaring two of them over two different schemas is a build error**,
because one `rules()` cannot hold two rule sets and [nothing rewrites it per request](#nothing-rewrites-the-rule-set).
Two media types over one schema are not a conflict and produce one rule set. **A part is a file only when
`multipart/form-data` is the body's one media type**: beside another, the part is read as a string, because one rule set
cannot hold both a `file` and the string JSON sends.

**A body schema that names `properties` and no `type` is read as an object.** JSON Schema applies `properties` to
objects only, so the schema says nothing about a string, but a body that names properties is an object whether or not it
spells `type: object`, and generators that omit the type are common. Only the body's root is read this way: a nested
field of the same shape gets no type rule, since `array` would refuse a string the contract accepts. The input DTO reads
the body the same way, so such a body has its rules and its `dto()`.

**A JSON body that is not an object is refused, for every object body.** Laravel reads a JSON body as
`(array) json_decode(...)`, so `"Ada"`, `42` and `[1,2]` reach the rules as a list that no rule has a key for, and an
open root with nothing required would accept all three. The generated request checks the root itself, in the same
`after()` closure as the closed root's key check, below: the raw body is decoded without `assoc`, because `{}` and `[]`
are both `[]` with it, and anything but an object is answered with Laravel's `array` message on a `body` key. A schema
that allows `null` allows it here, and the required properties do not refuse it either (see
[a nullable body, `null` or whole](#a-nullable-body-null-or-whole)). Only a request that is JSON is checked: a form
cannot send a non-object, so a body read only as a form has no such check.

**`[]` is a list, so it is refused for an object body, and a client has to send `{}`.** It is also what PHP's
`json_encode([])` writes, and so what Laravel's `postJson($uri)` and Guzzle's `'json' => []` send when they are given an
empty array. To send an empty object, give them `new stdClass` (or `(object) []`), or send the raw content `{}`. A test
of a consumer's own that posts an empty array to an object body will see this 422.

**The key is `body`, unless the contract has a top-level name or a `query` parameter that is `body`.** A property named
`body` is answered on the `body` key too, and so is a `query` parameter, which is merged into the same data, so the
client could not tell which of the two is wrong. The failure goes on `_body` then (and `__body`, and so on, until no
name of the contract is it). The key is written in the generated `after()`.

**An empty content is an absent body, and the body's `required` decides.** A required body sent as nothing is refused on
the body's own key, with Laravel's `required` message, since no rule says so when every property is optional (they are
all `sometimes`). An optional body sent as nothing passes, and `{}` is a body, an empty one: a required body accepts it
when no property is required, and an optional JSON body that requires properties refuses it, key by key. The check is
for a JSON body and a form body alike, and it reads any request whose type is not a form's as absent: one that is JSON,
one that is `text/plain` or `application/xml`, and one with no `Content-Type` at all (`curl -X POST url` and
`fetch(url, { method: 'POST' })` send none when there is no body). The type is compared without case, and its parameters
(`; charset=utf-8`) do not count. A request with no `Content-Type` carries no form field either, since a multipart
always sets one, and one with an uploaded file is never read as absent. What stays ambiguous is a form: a request with a
form `Content-Type` and no content has its body read from the form bag, whose fields need not show in the raw content
(PHP leaves `php://input` empty for `multipart/form-data`), so an empty `application/x-www-form-urlencoded` body and a
multipart with no parts are not read as an absent body, whatever the case its type is spelled in, and the generated file
says so in a finding whenever a required body is read as a form and no required field, or the body's required-key check,
already refuses an empty form. A required key asked with `present` or `required` (a required property, a required file
part, a required name `properties` does not declare) refuses a form that sends no field on its own, so the finding would
only be noise there. A key that follows another through `dependentRequired`, a `readOnly` one, a name the request leaves
unchecked and a `PATCH` ask nothing of an empty form, and keep the finding.

**For a body typed `object` that is the contract's own answer. For one that names `properties` and no `type`, it is
stricter than the contract, and it is the exception [the principle](#every-constraint-maps-or-reports) allows.** A body
`"Ada"` is valid against such a schema, and the request answers 422. Accepting it would take a root that is an object or
anything else, which Laravel cannot say: a rule needs a field to sit on, and a bare value has none. The generated file
says so in a finding when the body is read as JSON, and `type: object` in the schema makes the reading the contract's
own.

**Any other media type is reported rather than guessed at.** A body declaring `application/xml` is a contract this
package does not serve, and a `format: byte` string inside a JSON body is
[a string nothing decodes](../uploads.md#a-base64-part-is-a-string).

### Each field is read from where the contract put it

**A body field is read from the body and a `query` parameter from the query string, and neither is ever read from the
other.** Laravel validates `$this->all()`, which is the body with the query string merged in, and that answered the
wrong question in both directions. `POST /users?email=x@example.test` with a body of `{}` passed a contract that
requires `email` in the body, and a required `query` parameter was satisfied by a body key of the same name. The
generated request overrides `validationData()` so the rules see what the contract described:

```php
public function validationData(): array
{
    $query = array_flip(self::QUERY_KEYS);

    return array_diff_key($this->bodyInput(), $query) + array_intersect_key($this->query->all(), $query);
}
```

**`QUERY_KEYS` is the keys the declared `query` parameters arrive under, and it is the only thing taken from the query
string.** That is the name for most, and the head PHP delivers for one it rewrites (`page` for `page[size]`). Every
other key comes from the body, and a body key that happens to carry one of them is dropped, so it can neither satisfy
that parameter nor be refused for being the wrong type. A body key a rewritten parameter shares its head with is never
one of them, since that parameter has no rule and is not in the list. Which source a key has is no more a runtime choice
than `rules()` is: the build already knows, and writes it down as a constant. An operation with no body reads only the
query string, an operation with no `query` parameter reads only the body, and `rules()` is the same array in every case.

**The body is the JSON bag when the request is JSON and the form bag otherwise, with the uploaded files merged in the
way Laravel merges them.** It is never `input()` or `getInputSource()`, which answer with the query string on a `GET` or
a `HEAD`. Keeping the uploads as Laravel's own `Illuminate\Http\UploadedFile` is what lets a `file` rule judge one and
`dto()` receive one. The keys are combined with `array_diff_key()`, `array_replace_recursive()` and `+`, never a spread,
which renumbers an integer key: a property named `"2024"` is a valid payload.

**A value now arrives typed the way its source types it.** A JSON integer reaches `validated()` as an integer, and a
form-encoded or query value as the string it was sent as. Before, `?age=31` supplying a body field that the body left
out put the string `"31"` into `validated()` and into the DTO built from it.

**The closed-root check reads the same body**, through the same method, so what it refuses and what the rules validate
cannot disagree about what the body is.

### A name declared twice is refused

**A field named by both the request body and a query parameter is refused, naming the field and both positions.** Not a
precedence rule, because there is no reading under which one of them is right.

The reason is in the rule set rather than in the request.
[A key has one source](#each-field-is-read-from-where-the-contract-put-it) now, but `rules()` holds one entry per name
and `validated()` one value per name, so a body property and a query parameter of one name would still be two constraint
sets competing for one key, and the build would be choosing which half of the contract to enforce. It refuses instead,
the same shape as
[two `x-controller` values reducing to one parent](../controllers.md#the-contract-decides-what-is-customizable).

**The name that counts is the one Laravel reads, so a form body's delivered name is compared too.** A body read as a
form delivers `user.name` as `user_name` and `page[size]` below `page`, so a `query` parameter named `user_name`, or
`page`, is the same key as that body property and is refused the same way. The message names the body's name and the
parameter, which is the key a form delivers it under. A body that is JSON only keeps `user.name` as written, and
`user_name` beside it is two names.

**A rewritten `query` name under a key the body arrives under is not refused.** `page[size]` beside a body property
`page` would be the same shape of collision, but no rule is emitted for it, so the build is not choosing which half of
the contract to enforce: it keeps its finding, as [any name PHP rewrites](#one-rule-set-body-and-query) can. A plain
name is refused because it would have a rule.

**Path-item parameters merge with the operation's, and the operation wins on a name they share.** That is OpenAPI's own
rule, not one this package invents, and it is a merge rather than a collision.

### An optional body, all or none

**`requestBody.required: false` means the whole payload may be absent, and never that a required property became
optional.** So the rule set has to accept nothing and refuse half of something, and `present_with` says exactly that:
each field in the required list names every other key the body may carry, optional ones included. Naming only the
required ones would accept a body made of an optional key alone, which is a body, and one the contract refuses.

```php
// A requestBody that is not required, whose schema requires street, city and code, and declares note too.
'street' => ['present_with:city,code,note', 'string'],
'city' => ['present_with:street,code,note', 'string'],
'code' => ['present_with:street,city,note', 'string'],
'note' => ['sometimes', 'string'],
```

**What those three rules do, case by case:**

| The client sends                                 | Outcome                                                                                                                                               |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nothing                                          | Valid. No field sees a sibling present, so none of them is required                                                                                   |
| `{}`, or only keys the contract does not declare | Refused for a JSON request, by the generated `after()` (a `422` for a client that asks for JSON). Accepted for a form, and the generated file says so |
| `street` alone                                   | Refused. `city` and `code` both see `street`, and both become required                                                                                |
| All three                                        | Validated against the rest of their rules, exactly as a required body would be                                                                        |

When the body declares one key and requires it, there is nothing else to name, and it becomes `sometimes`. With two or
more required, each is asked for through the others, so a body that sends none of them asks for none. A rule cannot tell
a body sent as `{}` from one that is not sent, since both leave every key out of the data, so the rules alone would
accept `{}` whenever the body is optional and requires something. **A JSON request closes that gap in the generated
`after()`, which reads the raw content:** an empty content is an absent body and passes, and a JSON object that lacks a
required key is refused on that key, whatever else it carries (`{}`, or `{"zzz": 1}`), with the rule the key would get
in a required body. The keys are one `REQUIRED_KEYS` constant in the generated file, and a key `present_with` already
refused is named once. **Only a form keeps the gap**: an empty form cannot be told from an absent body, and a form's
required names arrive rewritten or nested (`user_name`, `page.size`), so the generated file says in a finding that a
form that sends none of the body's keys is accepted, which the contract refuses. (The empty content the request refuses
is a required body's, never an optional one's.) With nothing required, every field was already `sometimes` and an absent
body was already valid.

**The cost is noise, and it is bounded.** Eight required properties mean eight rules naming seven siblings each, in a
file nobody edits by hand. Nothing else in the rule set grows that way, and an optional body that requires properties is
a shape most contracts never write.

**Emptying the rule set at request time was considered and dropped.** A trait overriding `getValidatorInstance()` to
return no rules when `$this->validationData()` is empty reads better on the page, and it gives up two things:

1. **The declared query parameters are in the validated data.**
   [Query parameters share the rule set](#one-rule-set-body-and-query), so `POST /addresses?dry_run=1` with no body
   stops looking empty, the body's required fields fire, and a request the contract allows answers 422. `present_with`
   asks about the sibling fields instead, which is the question the contract asked.
2. **`rules()` stops being the whole answer**, which [nothing is allowed to do here](#nothing-rewrites-the-rule-set),
   whatever the hook it is done through. The `{}` refusal above sits in the generated `after()`, beside the root checks,
   and reads the raw body instead of changing `rules()`, so `rules()` stays the whole rule set and the generated file
   still holds the whole answer.

### A nullable body, `null` or whole

**A required body whose schema allows `null` accepts a JSON `null`, and its required properties ask through each other,
as an optional body's do.** Laravel reads a JSON `null` as `[]`, so a plain `present` on a required key would answer 422
to a payload the contract allows. The rules are the optional body's: each required key is `present_with` the others, and
`sometimes` when it has none. It holds for a body read as JSON, alone or beside a form, that is not a `PATCH` (a `PATCH`
and an optional body already let `null` through).

```php
// A required requestBody, type: [object, 'null'], whose schema requires publishAt and declares timezone too.
'publishAt' => ['present_with:timezone', 'string'],
'timezone' => ['sometimes', 'string'],
```

| The client sends                                 | Outcome                                                                                     |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| `null`                                           | Valid, and `dto()` returns `null`                                                           |
| `{}`, or only keys the contract does not declare | Refused, on each required key, by the generated `after()`                                   |
| A whole body                                     | Validated against the rest of their rules, exactly as a body that cannot be `null` would be |

**The check that keeps `{}` a 422 is the optional body's, with one difference.** A rule cannot see `{}`, so the
generated `after()` reads the raw content and refuses each key of `REQUIRED_KEYS` that is missing, with the rule it
would get in a body that cannot be `null` (`present`, or `required` for an integer, a number or a boolean). An optional
body may be absent, so there the check waits for a JSON object. A required body may only be `null`, so the check runs on
anything else: a form, a `text/plain` content or an empty one that lacks the keys is refused on them as it always was,
and a JSON `null` is the one request let through. Every other payload gets the status and the error keys the same body
gets when it cannot be `null`, open root or closed.

**A body that is JSON and a form passes a JSON `null`, and a form client gets the same statuses and keys.** A name PHP
rewrites (`user.name` arrives as `user_name`) stays unchecked there, as it is today, so it is neither in the siblings'
rules nor in `REQUIRED_KEYS`, and the form's answers are the ones it had.

**What a client sees change is two messages.** A required key missing beside a sent sibling now reports `present_with`'s
message ("must be present when ... is present") where it reported `present` or `required`, and an empty or `null`
integer, number or boolean reports `filled`'s ("must have a value") where it reported `required`'s; a key missing from
`{}` reports the rule it always did. The status and the keys are the same. And the generated file no longer carries the
finding about a form with no field when the body's required-key check refuses it.

**A required integer, number or boolean also gets `filled`.** `required` is implicit, so it refused `""` for them even
where nothing turns `""` into `null`; `present_with` and `sometimes` are not, and Laravel skips `integer:strict`,
`numeric:strict` and `boolean:strict` on a blank string. So `filled` takes `required`'s place beside the rule that asks
through the siblings, and a project without `ConvertEmptyStringsToNull` still refuses `""` there. A nullable property
keeps `present`, as before. Optional bodies do not get it: [their caveat](#patch-empties-the-required-list) stays.

## Nothing rewrites the rule set

**`rules()` is what the contract produced, and no trait, parent class or hook is allowed to change it.** It is the same
array for every request, except a root map's, or a root that declares properties beside a value schema, which adds the
integer-like keys the request carries ([below](#a-map-or-open-object-body-is-carried-whole)). The only other thing that
moves it is the contract moving and the build running again. A root map's added keys are no exception to what follows:
the file writes its rules once, under `*`, and a generated helper repeats them under each integer-like key the request
carries, so the file still shows every rule.

Three properties rest on that, and each one goes the moment a rule set carries a rule the file does not show:

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

| Declared            | `PUT`                                                                               | `PATCH`                   |
| ------------------- | ----------------------------------------------------------------------------------- | ------------------------- |
| In `required`       | `present`, or `required` for an integer, a number or a boolean that is not nullable | `sometimes`               |
| Not in `required`   | `sometimes`                                                                         | `sometimes`               |
| Every other keyword | [Mapped as below](#every-constraint-maps-or-reports)                                | The same rules, unchanged |

**A `readOnly` property is never asked for, whatever the list says.** OpenAPI 3.0 reads `required` on a `readOnly`
property as a promise about responses, so `POST /users` without an `id` is valid. 3.1 dropped that sentence and defers
to JSON Schema, where `readOnly` is an annotation and says nothing about presence. A 3.1 document is read the same way
anyway, by choice and not by rule, since a request that carries no server-assigned `id` is the case the keyword
describes. The property gets `sometimes` under every method, at any depth, and a finding names it. Sent anyway, it is
judged by its other rules, since a request that carries it is not one the contract forbids. **The input DTO follows the
rule set**: such a property is `Optional|T` on both types, because a body without it passes validation and reaches
`from()`, and a plain `T` would read a key nothing checked.

**`present` rather than `required`, because the two keywords do not mean the same thing.** JSON Schema's `required` asks
for the key; Laravel's asks for a non-empty value, and refuses `null`, `""`, `[]` and `{}` whatever else the field
declares. So a required string the contract lets be empty, a required array it lets hold nothing, and any field that may
be null would answer 422 to a payload the contract accepts. `present` asks for the key and nothing else, and the type
rule beside it judges any value that is not blank. `required` is kept for an integer, a number and a boolean, whose own
type rule refuses every empty value anyway, so there the two say the same thing.

**One empty value is refused anyway, and not by this package.** Laravel's default `ConvertEmptyStringsToNull` middleware
turns `""` into `null` before any rule runs, so a string that is not nullable refuses `""` over HTTP whatever presence
rule is emitted: `string` refuses `null`. A contract that means to accept an empty string declares the field nullable.
Removing the middleware is not the fix it looks like: Laravel skips every rule that is not implicit on a blank string,
so an optional integer, number or boolean would then accept `""`, which the contract refuses (a nullable required body
does not: it adds `filled`, see [above](#a-nullable-body-null-or-whole)). Pinned by an execution test rather than worked
around.

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

**A keyword becomes the Laravel rule that means the same thing, or it becomes a report.** A generated rule is never
looser than the contract, and never stricter either, in these words from the maintainer: _never stricter than the
contract when it is not way too complicated to integrate in Laravel_. The reasoning, and the alternatives turned down,
are in [ADR 0006](../../project/decisions/0006-hold-rules-to-the-contract.md).

- **Never looser: accepting a payload the contract refuses.** The rule has no exception. Where the code falls short of
  it today, the gap has its card or its stated limit, for example a JSON client's `"42"` in a body that is JSON and a
  form ([#133](https://github.com/Gcob/lara-spec-first/issues/133)), a text boolean's `"1"` and `"0"`, a blank string
  once `ConvertEmptyStringsToNull` is removed ([#157](https://github.com/Gcob/lara-spec-first/issues/157)), `pattern`'s
  `.`, and `format: email` ([#167](https://github.com/Gcob/lara-spec-first/issues/167)).
- **Never stricter: refusing a payload the contract accepts.** Every generated client then gets a 422 for something it
  was allowed to send, and nobody reading the contract can see why.
- **The one exception: when matching the contract exactly would be far too complex to integrate in Laravel, a stricter
  rule is allowed, and the generated file says so in a finding on the field.** A rule that is stricter in silence is a
  defect, and a stricter rule with its finding is a decision somebody can read. The cases today:
  [a boolean in a body that is JSON and a form at once](#every-constraint-maps-or-reports) keeps `boolean`, a file
  part's `mimetypes` refuses [a file named like PHP](../uploads.md#the-schema-names-the-file-part),
  [a recursive schema is refused below its depth](#a-recursive-schema-is-validated-to-a-stated-depth), and a body with
  `properties` and no `type` refuses a JSON root that is not an object. `ConvertEmptyStringsToNull` makes a non-nullable
  string refuse `""`, which is the framework's and not a rule here, so
  [it is stated above](#patch-empties-the-required-list) rather than reported per field.
- **A stricter rule without that reason:**
  [a JSON integer written as a float](../../project/decisions/0007-keep-json-scalars-strict.md) (`1.0`) is refused by
  `integer:strict` today, and a finding says so. Laravel's `integer` beside `numeric:strict` would accept it, and
  [#173](https://github.com/Gcob/lara-spec-first/issues/173) tracks the choice.

Which keywords are honored at all is [the support matrix](../openapi-support.md#schemas)'s to state; what each honored
one becomes is this table's.

**What a type, a nullability and an annotation each become:**

| Schema                                                  | Laravel rule                                                                                                                                                                                                                                      |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `type: string`                                          | `string`                                                                                                                                                                                                                                          |
| `type: integer`                                         | `integer:strict` in a JSON body, `integer` for a query parameter and a form or multipart field                                                                                                                                                    |
| `type: number`                                          | `numeric:strict` in a JSON body, `numeric` for a query parameter and a form or multipart field                                                                                                                                                    |
| `type: boolean`                                         | `boolean:strict` in a JSON body, or `Rule::in(['true', 'false', '1', '0'])` for a query parameter and a form or multipart field                                                                                                                   |
| `type: array`                                           | `array` and `list`, with the element rules keyed `field.*`                                                                                                                                                                                        |
| `type: object`                                          | `array`, with each property keyed `field.child`, or each value keyed `field.*` when it declares none and writes [a value schema](#a-maps-values-are-validated-one-by-one). A JSON object arrives as a PHP array, and Laravel has no `object` rule |
| `nullable`, `type: [..., "null"]`                       | `nullable`                                                                                                                                                                                                                                        |
| `enum`                                                  | `Rule::in()` with the declared values                                                                                                                                                                                                             |
| `const`                                                 | `Rule::in()` with the one value                                                                                                                                                                                                                   |
| `format: date`                                          | `date_format:Y-m-d`                                                                                                                                                                                                                               |
| `format: date-time`                                     | `regex:` carrying the RFC 3339 grammar, then `date`                                                                                                                                                                                               |
| `format: email`, `uuid`, `ipv4`, `ipv6`                 | `email`, `uuid`, `ipv4`, `ipv6`. Laravel's `email` accepts an RFC 5322 comment (`test(comment)@example.com`) that JSON Schema's refuses, which [#167](https://github.com/Gcob/lara-spec-first/issues/167) tracks                                  |
| `format: uri`                                           | Nothing. Laravel's `url` turns away the non-hierarchical URIs (`urn:…`) JSON Schema allows                                                                                                                                                        |
| `format: int32` on an `integer`                         | `min:-2147483648` and `max:2147483647`, each merged with the `minimum` or `maximum` the contract states: the tighter one wins                                                                                                                     |
| `format: int64` on an `integer`, `double` on a `number` | Nothing more. `integer:strict` is `is_int()` on the decoded JSON, and `integer` is PHP's `FILTER_VALIDATE_INT`: both refuse what a 64-bit integer cannot hold, and `numeric` takes any JSON number                                                |
| `format: password`                                      | Nothing. It is an annotation, and asserts nothing                                                                                                                                                                                                 |

**Laravel's `boolean` refuses the string `"true"`, so a boolean that arrives as text does not get it.** It accepts `1`,
`0`, `"1"`, `"0"`, `true` and `false`, and OpenAPI's default `form` style writes a boolean in a query string as the text
`true` (`?notify=true`), as a form-encoded or multipart body writes one. Every generated client would answer 422 to a
contract it follows, which is a rule refusing what the contract accepts. So for **a `query` parameter and a field of a
form-encoded or multipart body**, the rule is `Rule::in(['true', 'false', '1', '0'])`, and a JSON body gets
[`boolean:strict`](#a-json-body-keeps-its-types), where `true` arrives already typed
([ADR 0007](../../project/decisions/0007-keep-json-scalars-strict.md) holds the reasoning for the split). Four things
come with it:

- **`false` is not in the list as a PHP value.** `Rule::in` compares `(string) false`, which is the empty string, and a
  list holding it would accept the `null` an empty value becomes, which a non-nullable boolean refuses.
- **`validated()` holds the string as sent.** `validated('notify')` is `'false'`, and the generated file says so in a
  finding on the field. A form or multipart body has a DTO, and
  [its `from()`](./dto-anatomy.md#what-each-schema-type-becomes) reads the text with
  `filter_var(..., FILTER_VALIDATE_BOOL)`, since `(bool) 'false'` is `true`. A `query` parameter is not on the DTO, so
  the finding names the read that works there:
  `$request->query->has('notify') ? filter_var($request->validated('notify'), FILTER_VALIDATE_BOOL) : null`. `has()`
  comes first because `filter_var(null, FILTER_VALIDATE_BOOL)` is `false`, with or without `FILTER_NULL_ON_FAILURE`, so
  an optional parameter that was not sent would read as `false`. It is asked of the query bag, since the request's own
  `has()` reads the body too, where an open body carrying a `notify` key would make an unsent parameter read as `false`.
- **`"1"` and `"0"` are accepted although the contract writes `true` and `false`.** It is the maintainer's decision,
  kept because the four spellings are what a client of a query string or a form already sends, and the finding on the
  field says it accepts the two more.
- **A body that is JSON and a form at once keeps `boolean`.** One rule set cannot accept the form's text and still
  refuse `null`, so a form client's `"true"` is refused there, and a JSON client's `"1"` and `1` get through. The
  field's finding says both. The first is the exception [the principle](#every-constraint-maps-or-reports) allows when
  matching the contract exactly would be far too complex, and the second is
  [a JSON body's loose rule](#a-json-body-keeps-its-types) that no finding of the request being stricter covers.

Pinned by execution tests for the query string, a form body, a multipart body and a JSON body.

**`list` beside `array`, because Laravel's `array` passes for an associative one.** `{"tags": {"a": 1}}` would otherwise
satisfy a `tags` declared `type: array`, which is a payload the contract refuses being accepted. `list` is
`array_is_list()`, which is what a JSON array actually is, and the pair is what keeps that row honest.

**`date-time` is a grammar first and `date` second, because neither is enough alone.** Laravel's `date` accepts whatever
`strtotime` accepts, `next tuesday` included, which is looser than anything a contract meant to say. The `regex:` in
front of it holds the RFC 3339 shape, and `date` behind it adds the one thing a grammar cannot see, a calendar that
exists:

```php
'occurred_at' => ['present', 'string', 'regex:/^[0-9]{4}-(0[1-9]|1[0-2])-…(\.[0-9]+)?([Zz]|[+-]…)$/D', 'date'],
```

**Not `date_format`, and the reason is the fraction.** `date_format` passes only when `$date->format($pattern)`
reproduces the input exactly, and PHP has no variable-width fraction. Six patterns (`p` and `P` at whole seconds,
milliseconds and microseconds, because `p` prints `Z` for a zero offset where `P` prints `+00:00`) covered a fraction of
0, 3 or 6 digits. RFC 3339 allows any length, and the clients that matter send them: Go's `RFC3339Nano` writes up to
nine digits with the trailing zeros trimmed, and .NET's round-trip format writes seven. Both answered 422 on a valid
payload, so the rule is a pattern, which `\.[0-9]+` makes variable-width.

**What the pattern is careful about:**

- **`[0-9]`, not `\d`, and no `u` modifier.** Under `u`, PCRE's `\d` matches digits of other scripts. Every character of
  an RFC 3339 timestamp is ASCII, so the classes are too.
- **`D`**, so `$` does not match before a trailing newline, the same reason `pattern` carries it.
- **Hours below 24, minutes and seconds below 60, and the leap second `60`.** `date` alone would roll `24:00:00Z` over
  to the next day, so the grammar refuses it. The offset's hour is bound the same way, `+24:00` is refused.
- **`T` and `Z` in either case**, and `-00:00`, which RFC 3339 allows.

`2026-02-30T00:00:00Z` matches the grammar and is refused by `date`, which is the division of labour. An execution test
sends the payloads from each producer above, those refusals, and a trailing newline at the validator, since over HTTP
Laravel's `TrimStrings` removes it first.

**`format: float`, `byte` and the other values not in the table are reported**, and `spec:doctor` names them. `float` is
the one a reader would expect to be free: it is a 32-bit range with a precision no rule can check, so `numeric` is not
the same thing, unlike `double`.

Which `format` values are honored at all is [the matrix](../openapi-support.md#any-type)'s row.

**Strings and numbers, where the same two rule names do four jobs:**

| Schema                                 | Laravel rule                                                                                                           |
| -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `minLength`, `maxLength`               | `min`, `max` beside `string`, which counts characters                                                                  |
| `pattern`                              | `regex:`, [inside the boundary the matrix names](../openapi-support.md#strings-and-numbers)                            |
| `minimum`, `maximum`                   | `min`, `max` beside `integer` or `numeric`                                                                             |
| `exclusiveMinimum`, `exclusiveMaximum` | `gt:`, `lt:`, read from [the one spelling the normal form takes](../openapi-support.md#the-normal-form-a-schema-takes) |
| `multipleOf`                           | `multiple_of:`                                                                                                         |

**Arrays and objects, where a rule is keyed rather than named:**

| Schema                           | Laravel rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `items`                          | The element rules, under `field.*`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| `minItems`, `maxItems`           | `min`, `max` on the array itself                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `uniqueItems: true`              | `distinct` on `field.*` for integer or number elements, since JSON Schema compares numbers by value (`1` and `1.0` are one element, and so are `1` and `+1` in a form), and `distinct:strict` for string or boolean ones, when the elements are a typed scalar that may not be null, in an array that is not itself an element. Two numbers PHP compares as the same float count as one, past float precision (2^53 for an integer): `9007199254740993` and `9007199254740992.0`, or `0.1` and `0.10000000000000000001` in a form. The generated file does not report that limit. Reported otherwise: under a wildcard `distinct` compares across the whole outer list, it never compares object or array elements, and it lets two nulls through |
| `properties`                     | One key per property, `field.child`, recursively                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `additionalProperties: false`    | `array:` on a nested object's field, naming its declared keys                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| `additionalProperties: <schema>` | Each value of an undeclared key, under `*` at the root or `field.*` nested: [a map's values](#a-maps-values-are-validated-one-by-one)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `required` on a nested object    | `required_array_keys:` on the object itself, so a nullable object may still be `null`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `dependentRequired`              | `present_with:` naming the properties that trigger it                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `allOf`                          | Its branches merged into one schema first; two that cannot be said as one are refused                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

**A nested object is validated as an array, keyed with dots.** One rule key per property, however deep the schema goes,
which is Laravel's own notation for nested input and what `validated()` hands back:

```php
'address' => ['present', 'array:street,city,postal_code', 'required_array_keys:street,city'],
'address.street' => ['sometimes', 'string'],
'address.city' => ['sometimes', 'string'],
'address.postal_code' => ['sometimes', 'string', 'regex:/^[A-Z][0-9][A-Z] ?[0-9][A-Z][0-9]$/uD'],
```

**The dots stop at the validator.** What a controller reads is not that array: the generated request
[hands it a DTO](#the-payload-arrives-as-a-dto) whose properties are the schema's, nested objects included, so
`address.city` is a rule key and `$data->address->city` is how the value is read.

**`_with` is always `present_with`.** `required_with` refuses an empty value the way `required` does, and it also fires
only when the trigger is non-blank, so a body sent as `{"note": null}` would require nothing while the contract says any
key sent makes the rest required. Over HTTP the dependent's own type rule still refuses a blank value of its own, with
the caveat the paragraph on empty values above states: a bare `""` is turned into `null` by `ConvertEmptyStringsToNull`
before any rule runs, and it is the `null` the type rule refuses.

**The payload's root has no field for `array:` to sit on, so it gets an `after()` check instead.** The generated class
carries the body's declared top-level keys as a constant and refuses any other key in the body, read from the body (the
uploaded files of a multipart one included) by the
[same method the rules' data comes from](#each-field-is-read-from-where-the-contract-put-it), so a query parameter is
not mistaken for an undeclared field. Without it an undeclared field is simply absent from `validated()`, so it never
reaches a model either way; what the contract asked for and would not get is the request being refused.

**The refusal is Laravel's own `prohibited` message**, raised with `$validator->addFailure($key, 'prohibited')`, so an
undeclared key answers `The extra field is prohibited.` from `lang/en/validation.php`. A project's own wording and its
translations apply to it as they do to every other rule, which a sentence written into the generated class could not
honor, and the generated file's findings already say that messages come from there.

**Top-level keys only, and that is what lets the check run whenever the root closes itself.** Every nested object that
closes itself already refuses its own extras through `array:` on its own key, so the root's check has nothing to add
below the first level — and checking deeper would refuse the extras of a nested object that allows them, which would be
a rule stricter than the contract for no gain, since matching it here is not complex.

**Laravel's `#[FailOnUnknownFields]` was the other candidate, and it lost twice.** It does not exist on the lowest
Laravel this package supports, so it would have needed a version gate with this check underneath it anyway — two emitted
shapes that have to stay equivalent. And it measures every depth at once, so it could only have been used when no object
in the body allows additional properties at all.

**A property name containing a dot is escaped as `\.`**, because Laravel reads an unescaped dot in a rule key as
nesting. `user.name` as a literal property name would otherwise generate rules for a `name` key inside a `user` object
the contract never declared, which is a rule set that is wrong rather than incomplete.

**A rule that lists key names cannot carry one with a dot or a comma**, and the two such rules here are `array:` and
`required_array_keys:`. The comma splits the list, and Laravel has already read the data's dots as nesting by the time
the rule runs, so the name would match nothing and refuse every valid payload. Such a name keeps its own rule key,
escaped; what the parent cannot say about it — that it is required, or that nothing else is allowed — is reported in the
generated file's findings.

**A constraint that maps to nothing is named per operation by `spec:doctor`**, and by the generated file's own findings
for the reader who is already looking at the class. A few are still prose only (a form field PHP delivers under another
field's key, some `dependentRequired` entries, a JSON client's `"42"` on an integer of a body that is JSON and a form,
the rest listed in [#119](https://github.com/Gcob/lara-spec-first/issues/119)), so the doctor stays silent on them for
now. Neither is a fallback for the other: one runs in CI before anything is generated, and the other answers "why is
this field not enforced" at the moment somebody asks it.

**The doctor reads data, and the generated file reads prose.** A rule set keeps its findings as sentences, and some of
them are only worth saying: two media types over one schema, a rule stricter than the contract in one case. Gating an
exit code on those would fail a pipeline over a remark. So the builder also records, beside the sentence, the keyword
and the fields it was left unenforced on, for the four ways a keyword it reads comes out that way: a `format` no rule
means the same as, a `pattern` across the ECMA-262 / PCRE boundary, a `contentMediaType` no rule can match, and a
keyword stated where nothing reads it. A field the rule set drops whole is the same thing at larger scale: a query name
PHP appends to or delivers under no name, two that arrive under one key or one that shares its key with the body, and a
body key with a `*` Laravel reads as a wildcard, get no rule key at all, so its `required` and every keyword it states
are recorded for it, under the field's name. `required` on a `readOnly` property is not one of them: the request does
what the contract says there, since `required` takes effect on the response only, so the generated file says it in a
finding and the doctor has nothing to report. The `Ignored` keywords are not in a rule set at all, since nothing reads
them, and the reading collects them with their position instead. See
[the doctor's Support findings](../doctor.md#what-it-checks).

### A JSON body keeps its types

**A JSON body keeps its types: `integer:strict`, `numeric:strict` and `boolean:strict`.** JSON writes `42`, `1.5` and
`true` already typed, and `"42"` is a string. Laravel's loose `integer`, `numeric` and `boolean` read what a form or a
query string writes, so on a JSON body they would accept `"42"`, `"1.5"` and `1` for a boolean, which the contract
refuses. The reasoning is in [ADR 0007](../../project/decisions/0007-keep-json-scalars-strict.md). A field of a body
that is JSON and nothing else gets the `:strict` form of its rule, at every level (`a.b`, `items.*`):

- **`integer:strict` is `is_int()` on the decoded value.** `"42"`, `true` and `1.0` are refused. `min`, `max`, `gt`,
  `lt` and `multiple_of` beside it still compare as numbers, since Laravel finds an integer or a number by the rule's
  name, whatever follows the colon.
- **`numeric:strict` refuses a string**, `"1.5"` and `"2"` alike. An integer is a number, so `2` passes.
- **`boolean:strict` accepts `true` and `false` and nothing else**: not `1`, `0`, `"1"` or `"true"`.
- **A whole number written as a float is refused for an integer (`1.0`, `1e2`), and that is stricter than JSON Schema
  2020-12**, where `1.0` is an integer. `integer:strict` is `is_int()` on the decoded value, which refuses a float.
  Laravel's `integer` beside `numeric:strict` would accept it, and the package refuses it today: the generated file says
  so in one finding per request that has an integer field, and a request with none says nothing.
  [#173](https://github.com/Gcob/lara-spec-first/issues/173) tracks the choice, and
  [ADR 0007](../../project/decisions/0007-keep-json-scalars-strict.md) holds the rule.
- **A finding that says the request is stricter is not a gap.** It is in the Findings block like any other, but
  `spec:build` does not count it in its "read a constraint they do not enforce" warning, and the file keeps its "Nothing
  was read and left unenforced" line when it is the only kind there is. Otherwise that warning would fire on nearly
  every JSON request. The four are this one, `mimetypes` refusing a file named like PHP, a recursive schema refused
  below its depth, and a body with `properties` and no `type` refusing a JSON root that is not an object.
- **A finding that loses no constraint is not a gap either,** and is left out of the warning the same way. Two kinds
  exist: a body key of a map that is also the name of a `query` parameter (read from the query string, so the body's is
  left out), and an undeclared key a form delivers under a rewritten name (`a.b` as `a_b`) in a map body, or in a body
  that declares properties beside a value schema, where the value schema judges it under its new name.
- **A query parameter and a form or multipart field keep the loose rules**, since every value they carry is text.
- **A form client of an operation that declares only JSON gets the JSON rules.** `age=30` from an HTML form is a 422
  there, where it was a 200: the contract declares no form, so that client is off-contract, and `rules()` is one array.
- **A body that is JSON and a form at once keeps the loose rules, and a JSON client gets through what they accept.** One
  rule set cannot read the text a form carries and still refuse a string in JSON, so an integer there accepts `"42"` and
  `true`, a number accepts `"1.5"`, and a boolean accepts `"1"` and `1`, all of which the contract refuses. The
  generated file says so in one finding per field, and `spec:doctor` stays silent on it, like the other prose-only
  cases. [#133](https://github.com/Gcob/lara-spec-first/issues/133) is the runtime branch that would refuse them.
- **`integer:strict` needs Laravel 12.22.** Before it the parameter is silently ignored, and an integer would accept
  `"42"`. `numeric:strict` and `boolean:strict` exist from 12.21, so `integer:strict` is the rule that sets the floor,
  and the reason the package requires `^12.22 || ^13.0`.

### A map's values are validated one by one

**An object that writes a schema under `additionalProperties` has each value of a key it does not declare judged by that
schema's own rules.** The rules sit under one wildcard key, written after the object's own keys: `*` for a body that is
a map, `labels.*` for a property. A value is present by being in the object, so the rule carries no presence, as an
element of a list carries none. The reasoning is in
[ADR 0014](../../project/decisions/0014-judge-undeclared-keys-by-name.md).

```yaml
# workbench/openapi.yaml, the body of `PUT /posts/{postId}/labels`
type: object
additionalProperties: { type: string, maxLength: 30 }
```

```php
// app/Http/Generated/Requests/ReplacePostLabelsRequest.php
'*' => ['string', 'max:30'],
```

Written as a property named `labels`, the same schema gives `'labels' => ['sometimes', 'array']` and
`'labels.*' => ['string', 'max:30']`.

**A key the map requires without declaring it is judged by the wildcard too.** `required: [a]` with no `properties`
keeps `present` for `a`, the wildcard adds the value's rules to it, and the finding that says the request "says nothing
about the value" is not written there. **An empty value schema (`additionalProperties: {}`) is an open object**, as
`true` is: no rule and no finding. A key with a dot in it is the key as sent, never a path.

**Beside declared `properties`, a guard spares the declared names.** A plain wildcard reaches every key at its level, so
`id` beside a value schema would be held to it too. The generated request writes the wildcard as a rule of the package,
`UndeclaredKeys`, that gives a key no rule when its name is one the object declares:

```yaml
# workbench/openapi.yaml, the body of `PUT /posts/{postId}/metadata`
type: object
properties:
    locale: { type: string, maxLength: 5 }
additionalProperties: { type: integer }
```

```php
// app/Http/Generated/Requests/ReplacePostMetadataRequest.php
private const SPARED_KEYS = ['locale'];

'locale' => ['sometimes', 'string', 'max:5'],
'*' => new UndeclaredKeys([0 => self::SPARED_KEYS], ['integer:strict']),
```

`Gcob\LaraSpecFirst\Validation\UndeclaredKeys` is public API, imported by the generated request, so its name is frozen
with the rest of what a generated file names, and so are its constructor and `below()`, which the file calls.
`{"locale": "toolong"}` answers on `locale` alone, with `max:5`'s message and never `integer`'s, and `{"views": "x"}`
answers on `views`. The declared key keeps exactly its own rules whatever the order of the array. Every rule key under
the wildcard is guarded the same way (`meta.*`, and `meta.*.n` for an object value), so a value's own children never
reach a declared key either, and a value that is a map itself adds its own names to the guard of the keys below it. A
map with no declared property keeps the plain wildcard above.

**The guard reads the key's names and never the data.** Laravel's `Rule::forEach` does the same job and takes 22 seconds
for a map of 10,000 values, since it hands every key the whole data to undot. `UndeclaredKeys` takes 0.3 seconds for the
same map. It is quadratic as well, in Laravel's own expansion of a compiled rule under a wildcard: a map beside declared
keys costs about 7 seconds of validation per megabyte of map near 1 MB, and more than that past it. **Cap the request
body at the web server** (nginx's default `client_max_body_size` is 1 MB; PHP's `post_max_size` does not bound a JSON
body in time). A plain wildcard, a map with no declared key, stays linear.

**`validated()` holds the undeclared keys the guard judges, and `dto()` does not.** For `{"locale": "fr", "views": 3}`,
`validated()` is both keys, where the DTO of an object that declares properties carries `locale` only, and its file says
the values of the other keys are not read there.

**A root that declares properties beside a value schema keeps an integer-like key too.** Laravel renumbers a key such as
`"1042"` when it expands `*`, so the generated `rules()` repeats the guarded rules under each such key the request
carries, as it does for a map root, except under a declared name: copying over a declared `"2024"` would give it the
value schema's rules in place of its own.

**A declared name the guard cannot spare keeps the report.** Laravel replaces a `.` in a data key with a placeholder it
keeps private, and has no name for a `*`, so for these names the guard cannot tell a declared key from an undeclared
one. The values then stay reported and not validated: one finding says why, `spec:doctor` reports it as `Partial`, and
no value rule is written. Five cases, each with its own finding:

- **A name holding `*`:** "`meta` declares `x*y`, which Laravel cannot tell apart from an undeclared key, so the values
  of its other keys are not validated."
- **A name holding `.`,** read as JSON, or below the top level of any body (a form keeps the dot of a name inside
  brackets, `address[a.b]`): the same sentence, naming that name.
- **A form name nested in another at the top of a body** (`page[size]`, which PHP delivers as `page.size`): sparing
  `page` would leave an undeclared scalar `page` unjudged. The finding says "A form delivers `page[size]` inside `page`,
  so an undeclared `page` can't be told apart from it", and with `page` declared too, that "the guard can only spare
  `page` whole".
- **A name a body that is JSON and a form reads two ways** (`user.name`, which a JSON client sends as `user.name` and a
  form client as `user_name`): one rule set can't spare both.
- **An undeclared form key that arrives under a declared name's key.** `user.name` in a form body arrives as
  `user_name`, so the guard spares `user_name` and every other key is judged. A literal undeclared `user_name` arrives
  under the same key and is judged by `user.name`'s rules only, which is a gap the finding states ("an undeclared
  `user_name` is judged by `user.name`'s rules, not by the value schema") and `spec:doctor` reports as `Partial`. No
  public Laravel API gives a rule the original key, and PHP merges the two names itself, so nothing can close it today.

**A root map beside a `query` parameter is validated, and the guard spares the parameter's key.** The validated data
holds the body and the query string, so a plain wildcard would hold the parameter's key to the value schema. The root's
guard spares the keys the parameters arrive under (`page` for a parameter written `page[size]`) beside the declared
names, and the generated file reads them from the constant that lists the keys read from the query string:

```yaml
# workbench/openapi.yaml, `PUT /posts/{postId}/counters`
parameters:
    - { name: dryRun, in: query, schema: { type: boolean } }
requestBody:
    content:
        application/json:
            schema: { type: object, additionalProperties: { type: integer } }
```

```php
// app/Http/Generated/Requests/ReplacePostCountersRequest.php
private const QUERY_KEYS = ['dryRun'];
private const SPARED_KEYS = [...self::QUERY_KEYS];

'*' => new UndeclaredKeys([0 => self::SPARED_KEYS], ['integer:strict']),
'dryRun' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
```

`{"views": "x"}` answers on `views`, and `?dryRun=abc` answers on `dryRun` with its own message. A map nested in an
operation that has a `query` parameter needs no guard: its keys sit under a name of its own.

**Two branches of an `allOf` that each write a value schema are merged exactly:** the values must satisfy both, and a
value schema written by one branch also holds for the properties the other branch declares. A contradiction is refused,
as every `allOf` contradiction is, and `additionalProperties: false` in one branch closes the merged object.

### A recursive schema is validated to a stated depth

**A schema that refers to itself, directly or through another one, is validated down to the depth it states, and a
payload deeper than that is refused.** No rule set reaches an infinite depth, so the build unrolls the recursion into as
many levels as the schema asks for. The depth is `x-max-depth` on the recursive component, a whole number of zero or
more, and `3` when the schema says nothing. It is the package's first schema-level extension, read the same way at 3.0
and 3.1, and a value that is not a whole number is refused where the document is read, as the lifecycle extensions are.
There is no config key: the contract owns the shape of a payload. The reasoning is in
[ADR 0009](../../project/decisions/0009-cut-recursion-at-a-stated-depth.md).

```yaml
# workbench/openapi.yaml
Comment:
    type: object
    x-max-depth: 2
    required: [body]
    properties:
        body: { type: string, maxLength: 500 }
        replies:
            type: array
            items: { $ref: "#/components/schemas/Comment" }
```

```php
// app/Http/Generated/Requests/CreateCommentRequest.php
'body' => ['present', 'string', 'max:500'],
'replies' => ['sometimes', 'array', 'list'],
'replies.*' => ['array', 'required_array_keys:body'],
'replies.*.body' => ['sometimes', 'string', 'max:500'],
'replies.*.replies' => ['sometimes', 'array', 'list'],
'replies.*.replies.*' => ['array', 'required_array_keys:body'],
'replies.*.replies.*.body' => ['sometimes', 'string', 'max:500'],
'replies.*.replies.*.replies' => ['sometimes', 'array', 'list'],
'replies.*.replies.*.replies.*' => ['missing'],
```

**A level is one crossing of the recursion**, so `x-max-depth: 2` lets a payload re-enter `Comment` twice: a reply, and
a reply to that reply. **The limit at a crossing is the depth of the schema it re-enters**, counted per schema. A
recursive pair (`Author` holds `Book`s and `Book` holds an `Author`) therefore never has to agree with itself: entered
at `Author`, the pair reads `Author`'s depth, and entered at `Book`, it reads `Book`'s. Each operation unrolls from the
schema its own body starts at, so the same pair can be validated to two depths by two operations and neither is wrong.

**Below the depth the field must be absent, or `null` when the schema allows it, which makes a deeper payload a 422.**
The rule is chosen by what the recursive schema allows:

| The recursive schema | Rules below the depth                             | What passes               | What answers 422                    |
| -------------------- | ------------------------------------------------- | ------------------------- | ----------------------------------- |
| Not nullable         | `missing`, beside the presence the contract asks  | The key absent            | `null`, `""`, `[]`, `{}`, any value |
| Nullable             | The contract's presence rule, then `NullOrAbsent` | The key absent, or `null` | `[]`, `{}`, any other value         |

**The table is for a field that holds the recursive schema. A list of it is cut on its elements**, so an empty list
passes in both rows: it carries no data, and the depth exists to limit how much data a payload carries. A list with one
element below the depth answers 422 on that element. **A [map](#a-maps-values-are-validated-one-by-one) of it is cut on
its values the same way:** an empty map passes at the cut, and one value below it answers 422 on that key.

**`missing` is the key being absent.** **What the contract asks of the key stays beside it when the schema is not
nullable**: a required field at the cut is `present` and `missing` at once, which no payload satisfies, and that is the
contract's own tree, which a required non-nullable recursion never ends. Below the root the presence is the parent
object's `required_array_keys`, which the level above already carries, and a child's own presence is `sometimes`, so a
tree that stops earlier is untouched. **A nullable schema needs a rule of the package's own**,
`Gcob\LaraSpecFirst\Validation\NullOrAbsent`, imported by the generated request the way a generated DTO imports
`Optional`: `missing` refuses the `null` the contract allows, `prohibited` lets `""` and `[]` through, and `nullable`
does not skip an implicit rule, so no rule Laravel ships says "null or absent". The class is implicit, so Laravel calls
it for an absent key and for `""` too. **What the contract asks of the key stays beside it** (`present` for a required
one), so the last validated level of a required nullable recursion can write `"parent": null` and pass. **Over HTTP `""`
is `null` by then**: Laravel's `ConvertEmptyStringsToNull` middleware runs before any rule, so a nullable field takes
`""` for the `null` it allows and a non-nullable one refuses it like any other value. Execution tests pin each cell of
the table.

**That is stricter than the contract, and the generated file says so.** A payload five levels deep is valid against a
schema that states no limit, so this is the exception [the principle](#every-constraint-maps-or-reports) allows when
matching the contract exactly is far too complex in Laravel. **Each recursive schema the walk cuts gets one finding**
(`Comment` and `Address` in one request are two, among the file's others), and it names the schema, the depth it
applied, whether the schema stated it, the first field it refuses and how many more, which rule it carries there, what
`null` does (it passes only where the schema is nullable), whether an empty list passes, and `x-max-depth` as the lever.

**The rules unroll in `RuleSetBuilder`, and the contract's normal form does not change.** A recursion marker still
carries the position of the schema it points back at (`recursesTo`), and the builder keeps the definitions of the named
schemas it is inside of on a stack: a marker finds its ancestor there, by position, and expands it once more. A schema
in another file is found the same way.

**What goes on the stack is the component as the document writes it, never the schema that wraps it.** An `allOf` is an
intersection, so a constraint belongs to the level of the schema that wrote it:

- **A wrapper's constraints stay at the wrapper's level.** A body written as
  `allOf: [Category, {required: [name, slug]}]` requires `name` and `slug` on the root only. A child of a `Category` is
  a plain `Category`, so `{"name": "Root", "slug": "root", "children": [{"name": "Child"}]}` passes, as it did before
  the unrolling. The same goes for a field written that way, and for a named `CreateCategory: allOf: [Category, ...]`,
  whose `Category` is unrolled like any other.
- **What is written beside a marker applies at every level that marker is crossed at.**
  `next: allOf: [Node, {required: [label]}]` asks for `label` on `next`, and on `next.next`, down to the depth. The
  unrolled level is the ancestor with that constraint beside it, which is also what the contract says: every `next` is
  that intersection. At 3.1, `next: {$ref: Node, required: [label]}` is the same field, since the keywords written
  beside a `$ref` are a branch of that `allOf`
  ([how they are read](../openapi-support.md#keywords-written-beside-a-ref)).
- **`x-max-depth` written in a component's own inline `allOf` branch is the component's**, since the branch is part of
  it. One written on a component that is only included stays with that component.

**Two shapes keep the presence rule and the finding they had before the unrolling**, and the finding says that nothing
below the field is validated:

- **A recursion through a schema with no name.** An inline schema has no position a marker can be found by.
- **A field that is two markers at once**: two branches of an `allOf` that each write a field of one name pointing back
  at a different component. The walk follows one ancestor, and unrolling it would drop the other without a word. The
  finding names both.

**Unrolling is capped at 1,000 rule keys per operation, and when the stated depths do not fit, they are lowered, the
deepest schema first.** A schema that recurses once per level (`Comment`, `Address`) adds one copy of its rules per
level, so the default of three levels gives the repository's own recursive fixtures 12 rules for a two-field `Node`
where the walk used to give 3. A schema that recurses `k` times per level grows as `k` to the power of the depth: two
children (`left` and `right`) refuse `2` to the power of the depth plus one fields at the cut, 16 at the default depth,
and every level above them carries its own rules. That is harmless at the default depth and fatal at a depth a contract
plausibly states for a tree: a binary tree at `x-max-depth: 20` is over three million keys, which exhausts a gigabyte
before the build ends, and a request evaluates every key it holds. So the walk counts the keys it writes by unrolling
(the ones a schema holds without recursing do not count), and **a walk that passes 1,000 is thrown away and redone with
the depths lowered, until the rules fit**.

**Evenly is per schema.** A schema has one applied depth, so every branch of it (`left` and `right` alike) is cut at the
same level and none is left unvalidated. Across the schemas of one operation, the deepest applied depth is lowered
first, one level at a time, and a tie goes to the schema that wrote the most keys; a schema whose depth is under the one
that fits keeps its own. The build reaches that state without taking the steps: it finds by halving the deepest depth
that every schema can share and still fit, and then lowers, one by one and heaviest first, the schemas that sit one
level above it, until the rules fit. A failed attempt stops at the ceiling, so the build costs about the ceiling and
never the tree, whatever depth the contract states. A recursive pair is counted as two schemas, each lowered on its own.

**That makes the request stricter than the stated depth, and the finding says so**: it is the same cut finding, worded
for each schema it lowered with the stated depth, the ceiling, the depth that applies, and `x-max-depth` as the lever.
**The ceiling never refuses a contract**, and at a cap of zero nothing is unrolled, which always fits. The ceiling is a
number of keys because that is what a request pays for; the filter every search endpoint has (`and`, `or`, `not` over
itself) is 315 unrolled keys at the default depth, and a binary tree at depth 6 is 381, so a contract stays far under it
unless it asks for a branching schema to go deep. A binary tree stated at 20 is validated 7 levels deep (764 unrolled
keys; 8 levels would be 1,532).

**The input DTO is typed to match.** A recursive node is the class of the schema it points back at, so every level the
request validates is a DTO. A recursion the request did not unroll is `mixed`, and so is one with `properties` written
beside its reference, which no class of the ancestor can carry. See
[`dto-anatomy.md`](./dto-anatomy.md#what-each-schema-type-becomes).

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

**The generated request carries a `dto()` method returning a `final readonly` DTO built from the validated payload.**
The rule set says what a client may send; the DTO is that same statement as PHP types, so a controller reads
`$data->email` where it would have read `$validated['email']`, with the shape, the nullability and the autocompletion
the contract already described.

```php
// app/Http/Generated/Requests/CreateUserRequest.php
public function dto(): CreateUserInputDto
{
    $validator = $this->getValidatorInstance();
    assert($validator instanceof Validator);

    $excluded = $validator->excludeUnvalidatedArrayKeys;
    $validator->excludeUnvalidatedArrayKeys = false;

    try {
        return CreateUserInputDto::from($validator->validated());
    } finally {
        $validator->excludeUnvalidatedArrayKeys = $excluded;
    }
}
```

**It is `dto()` and not `data()`, which is what an earlier version of this page called it, and the framework is why.**
`Illuminate\Http\Request` already declares `data($key = null, $default = null)`, a protected method that `string()`,
`integer()`, `boolean()` and the rest of the typed accessors read through. A public `data(): CreateUserInputDto` on a
generated request is a fatal error at load, because a declaration has to be compatible with the one it overrides, and a
compatible one would change what every typed accessor returns. Found by running the generated class rather than by
reading it, so the test that pins it asserts the two facts the name rests on: `FormRequest` has a `data()`, and has no
`dto()`. A future Laravel that adds one fails that test rather than a consumer's boot.

**`dto()` reads the validated data with `excludeUnvalidatedArrayKeys` off, and puts the flag back.** By default
Laravel's `validated()` leaves out an array that has child rules when none of its declared children was sent. A required
object sent as `{}` passes `present|array` and is then absent from the data, so `from()` read an undefined key and the
request answered 500 on a payload the contract allows. A list of objects none of which holds a declared key lost every
element, and an optional `{}` arrived as `Optional`, which tells the controller the client did not send it. Kept as
sent, the array carries the keys the contract does not declare too, and `from()` reads only the ones it does, so none
reaches the DTO. The flag is on the validator Laravel builds, which is why the method asserts the class: the request is
`final` and declares no `validator()`, so it is always that one. Laravel 12 and 13 both have the flag, as a public
property of the validator.

**`validated()` keeps returning Laravel's array**, and `dto()` leaves it as it found it. It is the framework's own
method, `validated()` and `validated('email')` both have callers, and a generated class that changed what they hand back
would be this package redefining something it does not own. The DTO sits beside it, under a name of ours.

**`dto()` returns `null` for a `null` body, wherever a JSON body allows `null`.** When the body's schema allows `null`
and the body is read as JSON (JSON only, or JSON and a form), the request lets a JSON `null` through, and `dto()` is
typed `?CreateUserInputDto` with `if ($this->isJson() && trim($this->getContent()) === 'null') { return null; }` first.
The contract says the payload is `null`, and a DTO of `Optional`s would say "an object with nothing in it", which is
something else. That holds for an optional body, a `PATCH`, a required body that requires nothing, and a required body
that requires a property, which lets `null` through (see
[a nullable body, `null` or whole](#a-nullable-body-null-or-whole)). An absent optional body is not `null`: it has no
content, passes, and keeps the DTO of `Optional`s. A body that cannot be `null`, or a nullable one read only as a form
(a form cannot send `null`), keeps the plain return type.
[The reasoning](../../project/decisions/0010-return-null-for-a-null-body.md) is an ADR.

**Its properties are the body schema's, and a query parameter is not one of them.** (A body with none is
[carried whole](#a-map-or-open-object-body-is-carried-whole).) The rule set merges the two, so `validated()` carries
both; the DTO mirrors the body and nothing else, because the body is where its name comes from. Without that line the
naming rule below would not hold: two operations sharing one body `$ref` while declaring different query parameters
would be feeding two different key sets into one shared type. A query parameter stays on the request, where
`validated('page')` reads it and where [pagination](../pagination.md#parameters-must-be-declared) already looks for it.

**No factory on this side, and the asymmetry is the point.** A
[response DTO factory](./response-dtos.md#factories-carry-the-behavior) exists because a response is built from a model,
and mapping a model onto a response shape is where applications differ. An input DTO is built from the validated
payload, whose keys are the contract's own property names, so there is nothing to teach and nothing to override:
`from()` is a constructor call the build writes in full.

### A map or open-object body is carried whole

**A body that is an object with no `properties` has one property on its DTO, `additionalProperties`, and `validated()`
holds every body key.** The keys of such a body are the data, so `validated()` would hold none of them if no rule named
one: the root gets `'*' => ['sometimes']` where no value rule is emitted (an open object, an empty value schema), after
every explicit key, so the wildcard keeps each key without asking anything of it. A map whose values are validated has
its value rules under the same `*`. A controller that passed `validated()` to `create()` now passes the body, where it
passed `[]`.

```php
// app/Http/Generated/Data/ReplacePostLabelsInputDto.php
/**
 * @param  array<array-key, string>  $additionalProperties
 */
public function __construct(
    public array $additionalProperties,
) {}
```

**A map's values are typed by their schema, beside a `query` parameter too.** The request judges them there (its guard
spares the parameter's key), so the DTO types them as anywhere else: `array<array-key, int>` for a map of integers
beside `?dryRun=`, and a value class (`PetInputDto` for a map of `Pet`) is written for a map of objects. One class
serves every operation that reaches a named schema, and what its values are typed as depends on the schema alone, so the
classes are the same in every document order. The reasoning is in
[ADR 0015](../../project/decisions/0015-type-the-values-of-a-map-body.md).

**`dto()` takes the `query` parameters' keys out of the data before the DTO reads it**, since `validated()` holds the
body's keys and the `query` string's, and the DTO is the body's. Findings are written for such a body (the two below,
and the key Laravel drops, after them):

- **A map key named like a `query` parameter** is read from the query string, never from the body, so a body key of that
  name is left out of `validated()` and of the DTO. The finding names the key the value arrives under (`page` for a
  parameter written `page[size]`). A root that declares properties beside a value schema writes the same finding, since
  its undeclared keys are the map's.
- **A key a form delivers under another name** (`a.b` and `a b` as `a_b`) is validated and carried under the rewritten
  name, since the body declares no key to say which one the client meant. A JSON body keeps every key as sent.

**A map keyed by IDs keeps its keys and has their values judged.** `{"1042": 3, "877": 1}` is a valid body for a map of
integers, and `1042` is an integer key in PHP. Laravel expands `*` over data it has put through `array_merge()`, which
renumbers such a key, so the wildcard alone never reaches it: the key would be missing from `validated()` and its value
never judged. The generated `rules()` therefore writes every rule under `*` again under each integer-like key the
request carries (`1042`, `1042.n` for a map of objects, `1042.*` for a map of lists), which is why that method is built
from the body at request time for a root map. A key that is not an integer (`sku-1`, `a.b`) is the wildcard's, since
Laravel keeps it as it is, and an error answers on the key itself (`1042`, never a name the build made up). The `query`
parameters' keys keep their own rules.

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

**The third state is [`Optional`](./dto-anatomy.md#absent-is-optional), a class this package ships**, spelled the way
`spatie/laravel-data` spells it so the type reads the same to anyone who has used that package. It is public API surface
from the first release that emits a DTO.

### A `PATCH` takes the partial type

**Nullable and optional are two axes, and the generated type keeps them apart.** `nullable` comes from the schema and
says a value may be `null`. `required`, read under the operation's method, says whether the property may be absent at
all. Conflating the two is what turns a field the contract requires into a type that shrugs about it.

**What each combination becomes**, with the absent state spelled `Optional`:

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
separates them: a `readOnly` property is not something a request has to carry and is expected in a response, and an
optional property is optional for different reasons on each side. One class serving both would have to be the union of
two shapes, which is a type describing neither.

**The name comes from the schema when the schema has one, and the request side always carries `Input`.** A body written
as `$ref: '#/components/schemas/NewUser'` becomes `NewUserInputDto` and `NewUserPartialInputDto`, so every operation
sending that shape shares one pair. An inline body takes the operation's name, `CreateUserInputDto`, and an inline
object that is a value of a map takes its property's name with `Value` added (`CreateUserLabelsValueInputDto`), as an
element of a list takes `Item`. The marker is there whether or not a `$ref` was involved, because the same `$ref: User`
used in a request and in a response would otherwise give the two directions one name. **The name is the schema's and
never the `$ref`'s or the file's**: `./other.yaml#/components/schemas/NewUser` is the same `NewUserInputDto`, so
reorganizing a specification into files renames nothing, and two different schemas that give one class are a build error
naming the class and where each is written ([what it says](./dto-anatomy.md#one-class-three-members)). **A 3.1 `$ref`
with keywords beside it keeps the class of the schema it names when they add no shape a DTO holds**:
`Alias: {$ref: Node, maxLength: 3}` is `NodeInputDto`. One that writes `required`, `properties`, a type, `items`, `enum`
or `additionalProperties` (`false`, `true` or a schema) is a schema of its own, and gets a class after the alias when
the alias has a name, and after the operation or the field when it has not. The exact spellings are public API surface
under [rule 4](../openapi-support.md#the-four-rules) and belong to
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
what a static analysis error should be reporting. `spec:build` names it too, with the signature to paste
([the signature is the contract](../controllers.md#the-signature-is-the-contract)), because PHP itself refuses the class
only at its first load.

## The type hint is what runs it

**The generated request is `routeAction`'s first parameter, ahead of the path's own**, on the generated parent and on
every child that overrides it:

```php
// app/Http/Generated/Controllers/UpdateUserController.php
public function routeAction(UpdateUserRequest $request, User $user): UserDto
{
    return $this->update($user, $request->dto());
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

**What it does to the seam is the whole cost, and it is a shape change rather than an addition.** Before this package
emitted a request, the signature was the path's parameters alone, and a child controller has to match whatever the
parent declares. So the request parameter is part of the two-class seam rather than something beside it. Both sides read
it from one place,
[`RouteActionSignature`](https://github.com/Gcob/lara-spec-first/blob/main/src/Generation/RouteActionSignature.php):
`spec:make` writes the child, PHP forbids an override from widening, and two derivations of one signature is how a
scaffold becomes a fatal error at load.

**A path parameter named `{request}` is refused on an operation with something to validate.** The request is declared as
`$request` ahead of the path's own parameters, so the two would be one variable declared twice, which PHP refuses at
compile time. On an operation with nothing to validate the name is free.

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
