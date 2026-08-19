---
title: Pagination
audience: Users
covers: >
    Why OpenAPI has no vocabulary for pagination and the conventions specifications use instead, the adapter interface
    that normalizes both the request parameters and the response envelope, the one built-in driver with its mapping
    keys, and why pagination support is off until a project configures it. The driver mechanism itself is owned by
    drivers.md.
read_before: >
    Implementing anything that reads a paginated request or shapes a paginated response, or touching the pagination
    configuration.
tags: [openapi, pagination, decisions, scope, laravel]
---

# Pagination

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

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

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

**Open:** whether one interface covers page-based, offset-based and cursor-based pagination, or whether those are three
interfaces behind a common parent. They answer genuinely different questions — a cursor has no page number and an offset
has no last page — and flattening all three into one interface would mean methods that return `null` for whichever style
is not in use, which is the shape that eventually gets a `match` statement per caller. This needs settling against a
real specification of each kind before it is decided.

## Pagination is off until a project configures it

**Decision: there is no default convention, so pagination support does nothing until the driver and the mapping are
configured.** Not a driver that guesses, and not a driver picked for you: an unconfigured `pagination` block means the
package treats paginated endpoints as ordinary ones, which is exactly what it does today.

This is the same posture as [the remote reference allowlist](./remote-references.md), empty until a project declares a
host, and
[the factory override scan](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares),
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

## Open: the envelope and the generated DTO

The unresolved problem, and it is specific to pagination rather than inherited from rate limiting: a paginated
response's schema describes the **envelope**, not the item. So the [generated DTO](./code-generation.md#response-dtos)
for such an operation is a page object whose collection property holds items of another DTO's type — and PHP has no
generics to express _a page of `UserDto`_ in a signature that static analysis can check at
[level 8](../project/stack.md).

Three shapes are plausible, none chosen: one generated DTO per paginated operation, envelope and all, which is honest
and repetitive; one shared page DTO holding an `array` of items, which loses the item type exactly where the developer
wants it; or a shared page DTO plus a generated `@template`-style annotation that Larastan can read even though PHP
cannot enforce it. **This needs deciding before pagination is built**, because it decides what the build emits, and it
is the same class of question as [the two layers](./code-generation.md#two-layers) rather than a detail beneath it.

## Open questions

- The config key names — `pagination`, `driver`, `mapping`, and every key inside the mapping — are working names, not
  decided. Public API surface under [rule 4](./openapi-support.md#the-four-rules).
- Whether the mapping's dotted paths (`meta.total`) are the right way to point into a nested envelope, or whether that
  quietly reinvents a path syntax the package would then own.
- Whether [the doctor](./doctor.md) reports an operation that looks paginated while no driver is configured. It has no
  reliable way to know a response is a page, which is the whole premise of this document — so any such check is a
  heuristic, and a heuristic that fires on the wrong endpoint is worse than silence under
  [rule 2](./openapi-support.md#the-four-rules).
- How this interacts with [the mock server and Faker responses](../project/roadmap.md): a mocked paginated endpoint has
  to produce a coherent envelope, not a random one, or the mock contradicts the contract it was generated from.
