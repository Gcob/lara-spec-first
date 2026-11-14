---
title: Request Validation
audience: Users
covers: >
    How one operation becomes one `FormRequest`: which parts of the contract the rule set is derived from and which
    parameter locations stay the router's, what a name declared twice does to a build, why `PATCH` is `PUT` with the
    required list emptied and why nothing fills an absent field from a schema `default`, which JSON Schema constraint
    becomes which Laravel rule and what happens to the ones that map to nothing, why nothing rewrites the rule set at
    request time, why the generated request is `final` and where the three things a developer would extend it for
    already live, why the validated payload reaches the controller as a DTO and what an absent field is in it, where
    each field is read from (the body for a body field, the query string for a declared `query` parameter), which media
    types are read and why one operation declares one of them, what an operation with nothing to validate gets, and how
    the request reaches `routeAction` without changing what a custom child may override.
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
> - A body field is read from the body and a `query` parameter from the query string, never from each other: the request
>   overrides `validationData()` so the URL cannot satisfy a body field, nor the body a query parameter.
> - `PATCH` is `PUT` with the required list emptied, and no generated class ever fills an absent field from a schema
>   `default`.
> - A request whose body is an object has `dto()`, which returns the body as
>   [a generated input DTO](#the-payload-arrives-as-a-dto) built from `validated()`, and `validated()` itself keeps
>   returning Laravel's array.
> - A constraint Laravel has no rule for is reported by name, never dropped and never approximated by a looser one. A
>   rule is never stricter than the contract either, unless matching it exactly would be far too complex in Laravel, and
>   then the generated file says so in a finding.
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

| Built                                                                                                        | Reported and not enforced                                                                      |
| ------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| Every row of [the mapping table](#every-constraint-maps-or-reports): types, bounds, `format`, `enum`, arrays | A `format` no Laravel rule means the same as, `uri` the most common                            |
| `required` for an integer, a number or a boolean, `present` for every other type, `sometimes`                | A `pattern` across [the ECMA-262 / PCRE boundary](../openapi-support.md#strings-and-numbers)   |
| [`PATCH` reading the required list as empty](#patch-empties-the-required-list)                               | A recursive schema below the point it repeats, since no rule set reaches infinity              |
| [An optional body's "all or none"](#an-optional-body-all-or-none), and a nested object's required children   | A file part's `encoding.<part>.contentType`, which [`uploads.md`](../uploads.md) names as open |
| `allOf` merged, `dependentRequired`, `additionalProperties: false` at every depth                            | A field name carrying `*`, which Laravel reads as a wildcard and offers no escape for          |
|                                                                                                              | A `query` name PHP rewrites (`user.name`, `a b`, `page[size]`), which no rule key can find     |
|                                                                                                              | A form client's value for a top-level name PHP rewrites, in a body that is JSON too            |
|                                                                                                              | Two top-level names a form delivers under one key, which no rule can tell apart                |
| A `query` parameter, with its own `required`                                                                 | A bound on a field that states no single type                                                  |
| A name in `required` that `properties` does not declare, as `present` alone, since any value is allowed      |                                                                                                |
| [A file part](../uploads.md#the-schema-names-the-file-part) of a multipart body: `file`, `mimetypes`, a size |                                                                                                |

**A body declared only in media types this package does not read gets no class**, the same as an operation stating
nothing, and `spec:build` warns about it on a line of its own: unlike that operation, this one states something nobody
validates.

**Everything in the right-hand column is named, per field, in the generated file's own `Findings` block**, which is the
half that makes an incomplete rule set honest rather than misleading. Nothing is approximated by a looser rule, a
stricter one is a named exception, and nothing is dropped in silence: that is
[the principle](#every-constraint-maps-or-reports) the rest of this page is about, and it is what makes each pass safe
to ship on its own.

**The input DTO is built.** `spec:build` writes [the DTO](#the-payload-arrives-as-a-dto) of every body that is an
object, in the `Data` sub-namespace, and the request returns it from `dto()`. A request whose operation has no body, or
whose body is not an object, has no `dto()`: its `query` parameters are read with `validated('name')`.

## One rule set, body and query

**The rule set of an operation is derived from its `requestBody` schema and from its `query` parameters, merged by field
name into one `rules()` array.** One class, one array, one `$validated` for everything downstream to read.

```php
// app/Http/Generated/Requests/CreateUserRequest.php
public function rules(): array
{
    return [
        'email' => ['present', 'string', 'email', 'max:255'],
        'age' => ['sometimes', 'integer', 'min:18'],
        'notify' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],   // ?notify=true, a query parameter
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

**A query name PHP rewrites gets no rule at all.** PHP parses the query string before Laravel sees it: a `.` or a space
in a name becomes `_`, so `user.name` arrives as `user_name`, and `[` opens a nested array, so `page[size]` arrives as
`page => [size => 3]`. No rule key finds the name the contract writes, so a rule for it would never judge what was sent:
an optional parameter would never be validated, and a required one would answer 422 to every valid request. It is
treated as [a field name carrying `*`](#every-constraint-maps-or-reports) is: no rule, a finding that says why, and the
request accepts what the contract accepts. It is not in the list of names `validationData()` reads either.

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
and `page=abc` are refused as the contract refuses them. For a name with no bracket (`user.name`), nothing tells
`user_name` apart from it: the key check accepts a `user_name` the contract does not declare, and the generated file
says so, as it does for a bracketed name in a body that is JSON too, whose head is not closed.

**Two names a form delivers under one key get no rule, and one finding names them.** `user.name` and `user_name` arrive
as one key, and so do `page` and `page[size]` (the second is `page => [size => ...]`, below the key the first uses), so
the data cannot say which one was sent. Neither gets a rule, none takes part in `required_with` or `dependentRequired`
(a `dependentRequired` it triggers says which dependency it loses), and their DTO properties are `Optional`. Two names
that differ only by a character PHP rewrites also derive one PHP property name, so the DTO refuses them with its own
error before this finding is reached; it is the bracketed case that gets here in a build. The same holds when the body
is JSON as well: a rule for `page` would judge the array a form builds from `page[size]=3`, and a valid form client
would be refused, so the JSON client loses the rule on `page` too, and the finding says neither name has one.

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
that allows `null` allows it here. Only a request that is JSON is checked: a form cannot send a non-object, so a body
read only as a form has no such check.

**`[]` is a list, so it is refused for an object body, and a client has to send `{}`.** It is also what PHP's
`json_encode([])` writes, and so what Laravel's `postJson($uri)` and Guzzle's `'json' => []` send when they are given an
empty array. To send an empty object, give them `new stdClass` (or `(object) []`), or send the raw content `{}`. A test
of a consumer's own that posts an empty array to an object body will see this 422.

**The key is `body`, unless the contract has a top-level name that is `body`.** A property named `body` is answered on
the `body` key too, and the client could not tell which of the two is wrong, so the failure goes on `_body` then (and
`__body`, and so on, until no name of the contract is it). The key is written in the generated `after()`.

**An empty content is an absent body, and the body's `required` decides.** A required body sent as nothing is refused on
the body's own key, with Laravel's `required` message, since no rule says so when every property is optional (they are
all `sometimes`). An optional body sent as nothing passes, and `{}` is a body, an empty one, that passes either way. The
check is for a JSON body and a form body alike, and it reads two kinds of request as absent: one that is JSON, and one
with no `Content-Type` at all (`curl -X POST url` and `fetch(url, { method: 'POST' })` send none when there is no body).
A request with no `Content-Type` carries no form field either, since a multipart always sets one, and one with an
uploaded file is never read as absent. What stays ambiguous is a form: a request with a form `Content-Type` and no
content has its body read from the form bag, whose fields need not show in the raw content (PHP leaves `php://input`
empty for `multipart/form-data`), so an empty `application/x-www-form-urlencoded` body and a multipart with no parts are
not read as an absent body, and the generated file says so in a finding whenever a required body is read as a form.

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

**`QUERY_KEYS` is the declared `query` parameters, by name, and it is the only thing taken from the query string.**
Every other key comes from the body, and a body key that happens to carry a declared query name is dropped, so it can
neither satisfy that parameter nor be refused for being the wrong type. Which source a key has is no more a runtime
choice than `rules()` is: the build already knows, and writes it down as a constant. An operation with no body reads
only the query string, an operation with no `query` parameter reads only the body, and `rules()` is the same array in
every case.

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
`page`, is the same key as that body property and is refused the same way. The message names the body's name, the key a
form delivers it as, and the parameter. A body that is JSON only keeps `user.name` as written, and `user_name` beside it
is two names.

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

| The client sends | Outcome                                                                        |
| ---------------- | ------------------------------------------------------------------------------ |
| Nothing          | Valid. No field sees a sibling present, so none of them is required            |
| `street` alone   | Refused. `city` and `code` both see `street`, and both become required         |
| All three        | Validated against the rest of their rules, exactly as a required body would be |

When the body declares one key and requires it, there is nothing else to name, and it becomes `sometimes`: Laravel
cannot tell an absent body from `{}`, so `{}` is accepted, and the generated file says so. With nothing required, every
field was already `sometimes` and an absent body was already valid.

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
so an optional integer would then accept `""`, which the contract refuses. Pinned by an execution test rather than
worked around.

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
contract when it is not way too complicated to integrate in Laravel_.

- **Never looser: accepting a payload the contract refuses.** That is a wrong answer where a report is a missing one,
  and only the second is recoverable.
- **Never stricter: refusing a payload the contract accepts.** Every generated client then gets a 422 for something it
  was allowed to send, and nobody reading the contract can see why. A boolean the contract writes as `true` and a rule
  that wants `1` is the example this page had to reverse.
- **The one exception: when matching the contract exactly would be far too complex to integrate in Laravel, a stricter
  rule is allowed, and the generated file says so in a finding on the field.** A rule that is stricter in silence is a
  defect, and a stricter rule with its finding is a decision somebody can read. The cases today:
  [a boolean in a body that is JSON and a form at once](#every-constraint-maps-or-reports) keeps `boolean`, a file
  part's `mimetypes` refuses [a file named like PHP](../uploads.md#the-schema-names-the-file-part), and
  `ConvertEmptyStringsToNull` makes a non-nullable string refuse `""`, which is the framework's and not a rule here, so
  [it is stated above](#patch-empties-the-required-list) rather than reported per field.

Which keywords are honored at all is [the support matrix](../openapi-support.md#schemas)'s to state; what each honored
one becomes is this table's.

**What a type, a nullability and an annotation each become:**

| Schema                                  | Laravel rule                                                                                                            |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `type: string`                          | `string`                                                                                                                |
| `type: integer`                         | `integer`                                                                                                               |
| `type: number`                          | `numeric`                                                                                                               |
| `type: boolean`                         | `boolean`, or `Rule::in(['true', 'false', '1', '0'])` for a query parameter and a form or multipart field               |
| `type: array`                           | `array` and `list`, with the element rules keyed `field.*`                                                              |
| `type: object`                          | `array`, with each property keyed `field.child`. A JSON object arrives as a PHP array, and Laravel has no `object` rule |
| `nullable`, `type: [..., "null"]`       | `nullable`                                                                                                              |
| `enum`                                  | `Rule::in()` with the declared values                                                                                   |
| `const`                                 | `Rule::in()` with the one value                                                                                         |
| `format: date`                          | `date_format:Y-m-d`                                                                                                     |
| `format: date-time`                     | `regex:` carrying the RFC 3339 grammar, then `date`                                                                     |
| `format: email`, `uuid`, `ipv4`, `ipv6` | `email`, `uuid`, `ipv4`, `ipv6`                                                                                         |
| `format: uri`                           | Nothing. Laravel's `url` turns away the non-hierarchical URIs (`urn:…`) JSON Schema allows                              |

**Laravel's `boolean` refuses the string `"true"`, so a boolean that arrives as text does not get it.** It accepts `1`,
`0`, `"1"`, `"0"`, `true` and `false`, and OpenAPI's default `form` style writes a boolean in a query string as the text
`true` (`?notify=true`), as a form-encoded or multipart body writes one. Every generated client would answer 422 to a
contract it follows, which is a rule refusing what the contract accepts. So for **a `query` parameter and a field of a
form-encoded or multipart body**, the rule is `Rule::in(['true', 'false', '1', '0'])`, and a JSON body keeps `boolean`,
where `true` arrives already typed. Four things come with it:

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
  refuse `null`, so a form client's `"true"` is refused there and the field's finding says so. This is the exception
  [the principle](#every-constraint-maps-or-reports) allows when matching the contract exactly would be far too complex.

Pinned by execution tests for the query string, a form body, a multipart body and a JSON body. An earlier version of
this page called the 422 the price of not using a looser rule, and a test pinned it: the maintainer reversed that, since
the principle runs both ways and a rule refusing what the contract accepts is not a safe default.

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

| Schema                        | Laravel rule                                                                                                                                                                                                                                                                                         |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `items`                       | The element rules, under `field.*`                                                                                                                                                                                                                                                                   |
| `minItems`, `maxItems`        | `min`, `max` on the array itself                                                                                                                                                                                                                                                                     |
| `uniqueItems: true`           | `distinct:strict` on `field.*` when the elements are a typed scalar that may not be null, in an array that is not itself an element. Reported otherwise: under a wildcard `distinct` compares across the whole outer list, it never compares object or array elements, and it lets two nulls through |
| `properties`                  | One key per property, `field.child`, recursively                                                                                                                                                                                                                                                     |
| `additionalProperties: false` | `array:` on a nested object's field, naming its declared keys                                                                                                                                                                                                                                        |
| `required` on a nested object | `required_array_keys:` on the object itself, so a nullable object may still be `null`                                                                                                                                                                                                                |
| `dependentRequired`           | `present_with:` naming the properties that trigger it                                                                                                                                                                                                                                                |
| `allOf`                       | Its branches merged into one schema first; two that cannot be said as one are refused                                                                                                                                                                                                                |

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
sending that shape shares one pair. An inline body takes the operation's name, `CreateUserInputDto`. The marker is there
whether or not a `$ref` was involved, because the same `$ref: User` used in a request and in a response would otherwise
give the two directions one name. **The name is the schema's and never the `$ref`'s or the file's**:
`./other.yaml#/components/schemas/NewUser` is the same `NewUserInputDto`, so reorganizing a specification into files
renames nothing, and two different schemas that give one class are a build error naming the class and where each is
written ([what it says](./dto-anatomy.md#one-class-three-members)). The exact spellings are public API surface under
[rule 4](../openapi-support.md#the-four-rules) and belong to
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
