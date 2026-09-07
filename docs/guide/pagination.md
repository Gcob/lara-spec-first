---
title: Pagination
audience: Users
covers: >
    Why OpenAPI has no vocabulary for pagination and the conventions specifications use instead, the adapter interface
    that normalizes both the request parameters and the response envelope, the one built-in driver with its mapping
    keys, why pagination support is off until a project configures it, the envelope DTO the build emits per paginated
    operation, the two seams that let an operation paginate with no Eloquent model at all, and why pagination parameters
    must be declared in the specification. The driver mechanism itself is owned by drivers.md.
read_before: >
    Implementing anything that reads a paginated request or shapes a paginated response, or touching the pagination
    configuration.
tags: [openapi, pagination, decisions, scope, laravel]
---

# Pagination

> **TL;DR**
>
> - **Not built yet.** Phase 2, with the driver mechanism it shares with rate limiting.
> - OpenAPI has no vocabulary for pagination, so paginated endpoints are ordinary ones until a project names a driver.
> - One built-in driver ships, `laravel`, matching Laravel's own paginator envelope.
> - The build emits one envelope DTO per paginated operation, beside its item DTO and its metadata DTO.
> - An operation paginates with no Eloquent model at all, which is the case the two seams exist to prove.

OpenAPI describes a paginated endpoint the same way it describes any other: some query parameters go in, some object
comes back. It has no construct saying _this parameter is the page number_ or _this property holds the collection_ —
nothing a tool can read to know a response is a page of something rather than a thing.

The conventions that fill the gap are, if anything, more divided than the ones for [rate limits](./rate-limiting.md):

- **Page and size**, as `page` plus `per_page`, `page[number]` plus `page[size]`, or `pageNumber` plus `pageSize`.
- **Offset and limit**, which answers a different question and cannot be translated into the first without knowing the
  size.
- **Cursors**, as `cursor`, `after`, or `since_id` — deliberately opaque, and not convertible to either of the above.
- **The envelope**, which is where agreement breaks down completely: `data` beside a `meta` object, `items` beside a
  bare `total`, JSON:API's `data` plus `links`, HAL's `_embedded`, or the collection at the document root with the
  totals pushed into `Link` and `X-Total-Count` headers.

**This document deliberately mirrors [rate limiting](./rate-limiting.md)**, and for the same reason: the package cannot
discover any of it, guessing wrong is worse than declaring nothing under [rule 2](./openapi-support.md#the-four-rules),
and the honest answer is to make the project state which convention it uses rather than to invent a universal one and be
wrong for most readers. Both features are therefore [driver-based](./drivers.md), and that shared mechanism — structure
in code, names in config, a project free to write its own — is what makes stating it cheap.

> **Not implemented yet.** [Phase 2](../project/roadmap.md#the-driver-features), alongside the response DTOs it needs:
> the envelope is a generated type like any other. Items marked `Open` are undecided.

## Decision: an adapter behind one interface

**Decision: a `PaginationAdapterInterface`**, and everything downstream of it asks the interface rather than knowing
anything about the project's convention.

Where it differs from the rate-limiting adapter is worth stating plainly, because it is the harder half of this design:
**pagination runs in both directions.** A rate limit is read and reported. A page is _requested_ — the adapter has to
say which query parameters carry the page and the size, so the generated controller can accept them — **and returned**,
so the adapter also has to say where the collection and the totals sit in the response envelope. Working names, one per
question:

| Question                                       | Working name                               |
| ---------------------------------------------- | ------------------------------------------ |
| Which request parameters carry the page window | `getPageParameter()`, `getSizeParameter()` |
| Where the collection sits in the envelope      | `getCollectionKey()`                       |
| Where the totals sit                           | `getTotalKey()`, `getLastPageKey()`        |

**The three styles need three shapes, and Laravel already has them.** A cursor has no page number and an offset has no
last page, so flattening all three into one interface would mean methods returning `null` for whichever style is not in
use — the shape that eventually grows a `match` statement per caller. Rather than invent anything, the adapter hands
back [one of Laravel's own pagination contracts](#laravel-already-owns-the-source-agnostic-contract). Note that those
contracts are deliberately **not** one hierarchy, which is what the return type there has to account for.

**Open:** whether the adapter needs all five accessors above once the paginator contract carries most of that state, or
whether it narrows to the parts the specification alone knows — the parameter names and the envelope keys.

## Pagination is off until a project configures it

**Decision: there is no default convention, so pagination support does nothing until the driver and the mapping are
configured.** Not a driver that guesses, and not a driver picked for you: an unconfigured `pagination` block means the
package treats paginated endpoints as ordinary ones, which is exactly what it does today.

This is the same posture as [the remote reference allowlist](./remote-references.md), empty until a project declares a
host, and
[the factory override scan](./code-generation/index.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares),
which scans nothing until a project names a directory. A feature whose whole premise is _we cannot know your convention_
has no business assuming one on your behalf.

## One built-in driver

Pagination is [driver-based](./drivers.md), and that document owns the mechanism: the driver knows **where** the page is
expressed, the config mapping says **what this project calls each field**, and a project whose convention the built-in
driver does not cover [registers its own](./drivers.md#turnkey-by-default-yours-when-you-need-it) from its service
provider. What follows here is only what is specific to pages.

**Decision: the driver is configured, not detected**, and one ships with the package — the one this package can actually
justify, which is Laravel's own paginator output:

```php
'pagination' => [
    'driver' => 'laravel',
    'mapping' => [
        'page' => 'page',
        'size' => 'per_page',
        'collection' => 'data',
        'total' => 'meta.total',
        'last_page' => 'meta.last_page',
    ],
],
```

One rather than [rate limiting's two](./rate-limiting.md#two-built-in-drivers), and the asymmetry is deliberate rather
than an unfinished job. Rate limiting has two conventions that are each common enough to be worth shipping. Pagination
has too many to pick a second from without the choice being arbitrary — and it has one this package is uniquely
positioned to support, since `LengthAwarePaginator` is already what a Laravel application returns. Shipping the Laravel
shape is not a claim that it is the standard; it is the one whose behavior we can be sure of.

**Which makes this the feature where [a shared driver](./drivers.md#drivers-are-meant-to-be-shared) matters most.**
JSON:API, HAL, and cursor conventions are each coherent enough to be worth a driver and too numerous for this package to
ship — one built-in is a starting point for the ecosystem, not a claim that the others do not matter.

## One envelope DTO per paginated operation

A paginated response's schema describes the **envelope**, not the item, so a paginated operation needs more than one
generated type. PHP has no generics, so _a page of `UserDto`_ cannot be expressed in a signature static analysis can
check at [level 8](../project/stack.md).

**Decision: the build emits one envelope DTO per paginated operation, alongside the item DTO and a metadata DTO.**

```php
final readonly class UserPageDto
{
    /** @param list<UserDto> $data */
    public function __construct(
        public array $data,
        public UserPageMetaDto $meta,
    ) {}
}
```

Two alternatives were weighed and lost. **One shared page DTO holding an `array`** loses the item type exactly where a
developer wants it, which defeats the point of generating types at all. **A shared page DTO with a `@template`
annotation** keeps the type for Larastan while PHP enforces nothing, which is a real option but buys generality nobody
asked for at the cost of a type PHP cannot check.

The chosen shape is honest and repetitive, and the repetition is the price of the property that matters: the return type
of `routeAction` names exactly what comes back, per operation, and a schema change moves it.

## How a page is produced

**Decision: two seams, and only one of them depends on `x-model`.**

| Seam                                         | Question it answers              | Generated body                                                    |
| -------------------------------------------- | -------------------------------- | ----------------------------------------------------------------- |
| `getPaginator(): Paginator\|CursorPaginator` | Where does the page come from?   | From `getQuery()` when `x-model` is declared; otherwise it throws |
| `respondWithCollection(): …PageDto`          | How does it become the envelope? | **Always**, because the specification always knows the envelope   |

That split is what makes **pagination possible without `x-model` at all.** The mapping between a paginator's state and
the envelope's properties comes from the response schema and the config mapping, and neither of those depends on
Eloquent. So the package keeps doing that half of the work regardless, and a project supplies only the half it alone
knows.

### Laravel already owns the source-agnostic contract

**Decision: `getPaginator()` returns one of Laravel's own pagination contracts**, not a type this package invents. It
costs no new concept, which is the same argument that put middleware on
[Laravel's `HasMiddleware`](./controllers.md#middleware-is-a-method-not-a-separate-mechanism) rather than on an
interface of ours. And a page can be built from anything, so an operation with no Eloquent behind it is not shut out:

```php
new \Illuminate\Pagination\LengthAwarePaginator($items, $total, $perPage, $page);
```

**Those contracts do not form one hierarchy, and the return type has to say so.** This is worth writing out because the
short names hide it:

| Contract                                               | Extends     |
| ------------------------------------------------------ | ----------- |
| `Illuminate\Contracts\Pagination\Paginator`            | nothing     |
| `Illuminate\Contracts\Pagination\LengthAwarePaginator` | `Paginator` |
| `Illuminate\Contracts\Pagination\CursorPaginator`      | **nothing** |

So `getPaginator(): Paginator` would mechanically exclude cursor pagination — the third of the three styles this
document set out to cover. **Decision: the return type is the union `Paginator|CursorPaginator`**, which is what PHP
gives us for two unrelated interfaces and which stays honest about the fact that they are unrelated. An umbrella
interface of our own would be the alternative, and it loses: it would mean every third-party paginator has to implement
ours before this package will accept it, for no gain over naming both types.

**And the interface differs from the concrete class it is usually built from.**
`Illuminate\Contracts\Pagination\LengthAwarePaginator` is the interface a method returns;
`Illuminate\Pagination\LengthAwarePaginator` is the class the example above instantiates. Same short name, two
namespaces, and a document meant to be implemented as written should not leave that to inference.

### The case that proves the split: an operation with no model at all

An operation proxying an upstream paginated service wants no Eloquent, no query and no `x-model`, which is entirely
legitimate. It still gets `UserPageDto`, `UserDto` and `UserPageMetaDto` from its response schema, and it overrides one
method:

```php
// In the custom controller. The only thing the package could not know.
protected function getPaginator(): Paginator|CursorPaginator
{
    $upstream = Http::get('https://…/users', request()->query())->json();

    return new LengthAwarePaginator(
        $upstream['results'], $upstream['count'], 25, request()->integer('page', 1),
    );
}
```

`respondWithCollection()` stays generated and maps that paginator into the envelope. The developer writes where the data
comes from; the package writes the shape it goes into.

**And the `501` chain still holds.** `getPaginator()` is concrete but throws when nothing can supply a page, so the
generated class stays instantiable and answers `501`. Since a class with no
[`x-controller`](./controllers.md#the-specification-decides-what-is-customizable) is `final`, a paginated operation with
neither `x-model` nor `x-controller` answers `501` permanently — which is correct, because nothing has said where its
data would come from.

### What the generated body looks like

The mapping is resolved at build time, so the generated code contains the **resolved names** rather than a lookup:

```php
protected function respondWithCollection(): UserPageDto
{
    $paginator = $this->getPaginator();

    return new UserPageDto(
        data: UserDtoFactory::collection($paginator->items()),
        meta: new UserPageMetaDto(total: $paginator->total(), lastPage: $paginator->lastPage()),
    );
}
```

Nothing here reads configuration at request time, and nothing composes an object graph to hide what is happening. That
is the property [the docblock norm](./code-generation/index.md#every-generated-file-explains-itself) exists to serve:
open the file, read what it does.

Two details the generated code must take from the specification rather than invent:

- **The page size default comes from the parameter's declared `default`**, never a hardcoded `15`. A default the
  specification did not state would be code contradicting the contract, which is the one failure this package exists to
  prevent.
- **The parameter names come from [the mapping](#one-built-in-driver)**, so a project calling its size parameter
  `pageSize` gets `pageSize` in the generated code.

### Pagination parameters must be declared in the specification

**Decision: the package never accepts a query parameter the contract does not declare.** A tempting shortcut is for the
build to inject `page` and `size` validation into the [generated `FormRequest`](../project/roadmap.md) whenever an
operation is paginated. **It is rejected**, and the reason is the direction of truth rather than effort: parameters the
specification does not mention would be invisible to anyone reading it, and the code would have become authoritative
over what the API accepts.

The consequence runs the other way. **A paginated response whose operation declares no pagination parameters is a
[doctor](./doctor.md) finding**: the contract promises a page but gives a consumer no documented way to ask for one. The
mapping says what the parameters are called, the specification says they exist, and the doctor checks the two agree —
the same shape as
[the security scheme naming contract](./security.md#scheme-names-are-a-naming-contract-with-your-guards).

## Open questions

- The config key names — `pagination`, `driver`, `mapping`, and every key inside the mapping — are working names, not
  decided. Public API surface under [rule 4](./openapi-support.md#the-four-rules).
- Whether the mapping's dotted paths (`meta.total`) are the right way to point into a nested envelope, or whether that
  quietly reinvents a path syntax the package would then own.
- Whether [the doctor](./doctor.md) reports an operation that looks paginated while no driver is configured. It has no
  reliable way to know a response is a page, which is the whole premise of this document — so any such check is a
  heuristic, and a heuristic that fires on the wrong endpoint is worse than silence under
  [rule 2](./openapi-support.md#the-four-rules). This is a different question from
  [the declared-parameters finding](#pagination-parameters-must-be-declared-in-the-specification), which fires only once
  a driver has already established that the response is a page.
- The seam name for a collection that is **not** paginated. It has the same need as `getPaginator()` and a different
  shape — items rather than a page — so it is probably a second method, generated in its place, and that is one more
  public name to settle.
- How this interacts with [the mock server and Faker responses](../project/roadmap.md): a mocked paginated endpoint has
  to produce a coherent envelope, not a random one, or the mock contradicts the contract it was generated from.
