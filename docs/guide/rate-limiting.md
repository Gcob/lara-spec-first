---
title: Rate Limiting
audience: Users
covers: >
    Why OpenAPI has no vocabulary for rate limits and the two informal conventions specifications use instead, the
    adapter interface that normalizes reading either one, the two built-in drivers with their mapping keys, why the 429
    response should be a single referenced declaration, and how several named windows are expressed while one window
    stays unnamed. The driver mechanism itself is owned by drivers.md.
read_before: >
    Implementing anything that reads or reports a rate limit, or touching the rate-limiting configuration.
tags: [openapi, rate-limiting, decisions, scope, laravel]
---

# Rate Limiting

> **In brief**
>
> - **Not built yet.** Phase 2, with the driver mechanism it shares with pagination.
> - OpenAPI has no vocabulary for rate limits, so the driver is configured and never detected.
> - Two built-in drivers read a limit: `headers` from documented response headers, `extension` from a custom `x-` key.
> - Windows are first-class from the first release, because a dimension added later costs a major.
> - Declare the 429 response once and `$ref` it, so that a doctor finding means a real inconsistency.

OpenAPI has no construct for rate limits — no `security`-style object, no reserved keyword, nothing every tool agrees to
read. Two informal conventions fill the gap in the wild, and neither is universal:

- **A custom `x-` extension**, naming its own fields for the limit, the remaining count, and the reset — whatever the
  spec's author called them.
- **Documented response headers**, under `responses.<code>.headers`, using whichever names that project settled on — the
  legacy `X-RateLimit-*` prefix, the newer unprefixed `RateLimit-*` the IETF draft proposes, or something a team
  invented before either was common.

Neither convention is something this package can discover — there is no `operationId`-style name that means the same
thing everywhere, and guessing wrong here is worse than declaring nothing, per
[rule 2](./openapi-support.md#the-four-rules).

> **Not implemented yet.** [Phase 2](../project/roadmap.md#the-driver-features). One thing has to be settled before the
> adapter is more than an interface, and the roadmap says so too: whether what reads it is
> [build-time enforcement or a runtime relay](#open-what-the-adapters-answer-actually-powers). Items marked `Open` are
> undecided.

## Decision: an adapter behind one interface

**Decision: a `RateLimitAdapterInterface` returning a list of windows**, each one a `final readonly RateLimitWindow`
carrying its name, its limit, its remaining count and its reset. Everything downstream of it — reporting, and whatever
emits a response — asks the adapter for its windows and nothing else, regardless of which convention a given project's
specification actually uses.

**A list of value objects rather than three flat getters, and the reason is a compatibility one.** Three methods —
`getLimit()`, `getRemaining()`, `getResetTime()` — read better for the single-window case, and they were the first
sketch here. But [multiple windows are supported from the start](#windows-are-first-class-from-the-start), and
[drivers are written by third parties](./drivers.md#drivers-are-meant-to-be-shared): an interface that assumes one
window cannot grow a window argument later without breaking every driver anybody has published against it, which
[rule 4](./openapi-support.md#the-four-rules) makes a major release. The dimension has to be in the signature on day one
or it costs a major version to add. Collapsing the three getters into one object per window is what makes that
affordable rather than repetitive.

**`RateLimitWindow::$reset` normalizes to one spelling, the same problem this package already solved for `x-sunset`** —
several ways to write one moment have to come out as one thing, not as a value the caller must know to interpret
differently depending on where it came from. The IETF draft's `RateLimit-Reset` is _seconds until_ reset; the legacy
`X-RateLimit-Reset` is usually an _absolute_ Unix timestamp. Two conventions describing one moment cannot leak that
difference past the adapter.

## Two built-in drivers

Rate limiting is [driver-based](./drivers.md), and that document owns the mechanism: a driver knows **where** the limit
is declared, the config mapping says **what this project's fields are called**, and a project that needs a convention
neither built-in driver covers [registers its own](./drivers.md#turnkey-by-default-yours-when-you-need-it) from its
service provider. What follows here is only what is specific to rate limits.

**Decision: the driver is configured, not detected**, exactly because detection has nothing reliable to key off. Two
ship with the package:

```php
'rate_limiting' => [
    'driver' => 'headers', // or 'extension'
    'mapping' => [
        'limit' => 'RateLimit-Limit',
        'remaining' => 'RateLimit-Remaining',
        'reset' => 'RateLimit-Reset',
    ],
],
```

The same shape, pointed at extension fields instead of header names:

```php
'rate_limiting' => [
    'driver' => 'extension',
    'mapping' => [
        'limit' => 'x-ratelimit-limit',
        'remaining' => 'x-ratelimit-remaining',
        'reset' => 'x-ratelimit-reset',
    ],
],
```

Two built-ins are not two choices: a convention neither of them covers is a
[driver the project writes itself](./drivers.md#turnkey-by-default-yours-when-you-need-it), which is the mechanism's
whole point rather than an escape hatch.

Both examples above are the **single-window shorthand**, which is what most projects write and is
[explained below](#windows-are-first-class-from-the-start) — a `mapping` always describes windows, and one unnamed
window is the common case spelled without ceremony.

## The 429 response: use a `$ref`, and the reason is not only DRY

A rate-limited API declares `429` on every rate-limited operation, which in a real specification means dozens of copies
of one response. **Writing it once under `components.responses` and referencing it is strongly recommended** — and DRY,
which would be a sufficient argument on its own, is the weaker of the two here.

The stronger one is that **the mapping is matched against what the document actually says.** Configure `limit` as
`RateLimit-Limit`, and every 429 that spells it `Ratelimit-Limit` or `RateLimit-Limt` is a header the driver does not
find. With one referenced declaration there is one place for that typo to exist, and it is wrong everywhere at once —
loud, and fixed in one edit. With forty inline copies, thirty-nine work and the fortieth silently reports no limit on
one endpoint, which is precisely the shape of failure [rule 2](./openapi-support.md#the-four-rules) exists to prevent.

This is specific to rate limiting rather than shared with [pagination](./pagination.md), and the asymmetry is real: a
429 is genuinely the same response everywhere, while a paginated `200` wraps a different item type per operation and has
much less to share.

### Should the `$ref` be imposed, and should the driver ask for it instead of field names?

**Leaning: no to both, and the second is a technical answer rather than a preference.**

**The driver cannot see a `$ref`.** References are resolved in `OperationExtractor` before anything downstream runs, so
by the time a driver reads a response it holds inlined content with no record of where it came from — a referenced
declaration and a hand-written copy are byte-identical at that point. Pointing the config at
`#/components/responses/TooManyRequests` would mean preserving reference provenance through resolution, against the
grain of a pipeline whose whole design is that
[nothing downstream knows how the document was assembled](./openapi-support.md#what-is-shared-and-what-is-version-specific).

**And it would not remove the mapping anyway.** A reference locates the response; it says nothing about which header
inside it means _limit_. Config naming a ref would still need field names beside it, so the typo surface would not
shrink — it would just move.

**Imposing it is too strong**, for the reason the [support levels](./openapi-support.md#support-levels) already encode:
a 429 written inline is a valid document this package _can_ honor, and `Rejected` is reserved for what it cannot.
Refusing it would be inventing a requirement OpenAPI does not have.

**What is worth enforcing is the outcome, not the mechanism.** What actually breaks a driver is not an absent `$ref`, it
is two 429 declarations that disagree — so that is the finding: **the doctor reports 429 responses that are not
structurally identical across operations**, and names the operations that differ. A project using one reference passes
it by construction, which is the honest way to make a recommendation bite. That check has to run where references are
still visible, which is the decoded document, alongside the [cycle check](./openapi-support.md#reading-a-document) —
after resolution there is nothing left to compare.

### Two rate limits, one response

The question that follows: a burst limit of 60 per minute _and_ a daily limit of 1000 are two policies, so are they two
references, two config blocks, two drivers?

**No, and OpenAPI settles it: an operation has exactly one 429.** The Responses Object is a map keyed by status code, so
a second `429` entry is not something a document can express. One response, one reference, one config block — the
multiplicity has to live inside the mapping or not at all.

What actually differs between the two policies is **values, not structure**, and values are runtime data rather than
anything the specification declares. Both are a limit, a remaining count and a reset; `60` versus `1000` changes nothing
about the shape the driver reads. Where a project publishes both under the same field names — the IETF draft's approach,
one `RateLimit-Limit` plus a `RateLimit-Policy` describing the windows — there is nothing to duplicate at all.

The case that genuinely needs more is a project naming its windows separately, `X-RateLimit-Limit-Minute` beside
`X-RateLimit-Limit-Day`. That is still one response and one reference; it is six fields instead of three.

### Windows are first-class from the start

**Decision: a `mapping` always describes windows, plural, and several are supported from the first release** — per
minute, per hour, per day, per week, whatever a project publishes. Not deferred, and not a later addition, for the
reason [the interface section](#decision-an-adapter-behind-one-interface) gives: third-party drivers implement against
this shape, so a dimension added afterwards costs a major version. It is cheaper to support many windows now than to
support one and regret it.

**And a single window pays no ceremony for that.** The name exists to _distinguish_ windows, so with one there is
nothing to distinguish and no name to write:

```php
// One window: name it nothing. This is the common case.
'mapping' => [
    'limit' => 'RateLimit-Limit',
    'remaining' => 'RateLimit-Remaining',
    'reset' => 'RateLimit-Reset',
],
```

```php
// Several: now the names carry information, so they are required.
'mapping' => [
    'minute' => [
        'limit' => 'X-RateLimit-Limit-Minute',
        'remaining' => 'X-RateLimit-Remaining-Minute',
        'reset' => 'X-RateLimit-Reset-Minute',
    ],
    'day' => [
        'limit' => 'X-RateLimit-Limit-Day',
        'remaining' => 'X-RateLimit-Remaining-Day',
        'reset' => 'X-RateLimit-Reset-Day',
    ],
],
```

**An unnamed window resolves to the name `default`, and writing `default` explicitly changes nothing.** That is the same
principle as [`x-audience` and `x-lifecycle` defaults](./lifecycle.md#two-keys-one-discriminator), which are resolved
when they are read so that adding the key by hand produces no difference: the effective value is what the package
carries, never what somebody happened to type. Internally there is always at least one named window, so nothing
downstream needs a branch for "the single case" — the shorthand is a spelling, not a second model.

**One shape, not two, is what makes this worth doing.** The alternative — a `mapping` key for one window and a separate
`windows` key for several — would be two ways to state one fact, which is what this package exists to avoid. Here there
is one key whose values are either field names or named groups of field names, and those are told apart by their type: a
string is a field, an array is a window. **Mixing the two levels in one `mapping` is a hard error**, naming the
offending key, rather than a guess about which level was meant.

**Open:** whether the reserved name `default` is configurable or fixed, and whether a driver may declare that its
convention only ever has one window — the IETF draft's single `RateLimit-Limit` plus `RateLimit-Policy` arguably does,
and a driver that knows this could reject a multi-window mapping as a misconfiguration rather than reading fields that
cannot exist.

## Open: what the adapter's answer actually powers

The interface only has getters, and what reads them is not yet decided. Two candidates, and they are not the same
feature:

- **Build-time:** the `extension` driver's mapped fields carry an actual numeric threshold in the specification, and the
  build reads it to attach Laravel's own `throttle` middleware to the generated route with the right limit — the spec
  becomes the source of truth for enforcement, not only for documentation.
- **Runtime relay:** the application already enforces its own limits (Laravel's `RateLimiter`, or infrastructure in
  front of it), and this package's only job is making sure the response carries that state under whichever header names
  — or extension-shaped field — the specification promises, translating Laravel's own conventions into the project's.

These are not mutually exclusive, but which one ships first, and whether the `extension` driver is expected to carry a
real threshold at all rather than just naming fields, needs deciding before the adapter is more than an interface.

**Windows sharpen the difference rather than complicating it.** A relay echoes every window it was given and does not
care how many there are. A build-time reader has to hand Laravel's `throttle` middleware a single threshold, so it needs
a rule for which window wins — or several middleware instances, one per window, which is what
`throttle:60,1;throttle:1000,1440` already expresses and is probably the honest answer. That is another reason the
[window dimension had to exist first](#windows-are-first-class-from-the-start): it is load-bearing for whichever of
these two ships, not a refinement on top of them.

## What this document does not cover

Three boundaries, because a document about rate limits invites all three questions:

- **This package does not enforce a limit.** It reads what the contract declares about one. Whether that declaration
  ever reaches Laravel's `throttle` middleware is
  [the open question above](#open-what-the-adapters-answer-actually-powers), and until it is settled, enforcement is the
  application's.
- **Nothing here decides who the caller is.** A limit is per-something, and the package has no opinion on what that
  something is: authentication and identity are [`security.md`](./security.md)'s subject.
- **The mechanism the driver plugs into** belongs to [`drivers.md`](./drivers.md). This page owns the two built-in
  drivers and what a limit looks like in a document, not registration or the mapping split.

## Open questions

- The config key names — `rate_limiting`, `driver`, `mapping` — are working names, not decided. Public API surface under
  [rule 4](./openapi-support.md#the-four-rules).
- Whether [the doctor](./doctor.md) validates the mapping (a required key missing, a driver name it does not recognize)
  the same way it validates
  [the security scheme naming contract](./security.md#scheme-names-are-a-naming-contract-with-your-guards). With
  [windows](#windows-are-first-class-from-the-start) the check has a second half worth stating: a configured window
  whose fields appear in no 429, and the reverse — a 429 declaring fields no configured window claims, which is a limit
  the contract publishes and the package reads as nothing.
- Whether a document that declares a rate limit through neither convention — silence — is worth a doctor finding at all,
  given the package has no way to know a limit exists in the first place.
