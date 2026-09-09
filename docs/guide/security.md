---
title: Security & Permissions
audience: Users
covers: >
    How an operation's OpenAPI `security` becomes an actual authorization check: matching a `securitySchemes` name to a
    Laravel guard by nomenclature, the one built-in middleware that enforces a scope or permission regardless of the
    auth driver, and the boundary past which the spec has no vocabulary left and a Policy takes over.
read_before: >
    Implementing anything that maps `security` to middleware, or touching authorization.
tags: [openapi, security, decisions, scope, laravel]
---

# Security & Permissions

> **In brief**
>
> - **Not built yet.** The doctor reports `security` and nothing enforces it. Enforcement is Phase 2.
> - A `securitySchemes` name matches a Laravel guard by the name itself, never by a mapping you write out by hand.
> - One middleware asks one question: does the authenticated model carry the scope this operation asks for.
> - The build writes that requirement into the generated route, so nothing reads the specification while a request is
>   being served.
> - Past the scope check, authorization is a Policy's job, and the specification has no vocabulary left for it.

OpenAPI can say an operation requires `bearerAuth` with scope `write`. It cannot say whether the caller may touch _this_
record, because the specification has no vocabulary for the row being written. This document owns the line between the
two: what the package enforces because the contract actually says it, and what it deliberately leaves to the application
because the contract cannot.

> **Not enforced yet, and reported in those words.** Enforcement is
> [Phase 2](../project/roadmap.md#authorization-the-contract-can-express). What
> [Phase 1](../project/roadmap.md#reading-reporting-refusing) owes an operation that declares `security` is shipped:
> [`spec:doctor`](./doctor.md#what-it-checks) names every one of them, individually, on every run, and says the package
> does not apply the requirement yet. A phase that registers routes must not let a documented promise be mistaken for a
> kept one. A contract that declares `security` therefore exits non-zero until enforcement lands. Items marked `Open`
> are undecided.

## Scheme names match guard names

**Decision: a `securitySchemes` name matches a Laravel guard by nomenclature, not by a config mapping written by hand.**
A scheme named `sanctumAuth` in the specification names a guard called `sanctumAuth` in your `config/auth.php`, and no
new config key sits between the two:

```yaml
# openapi.yaml
securitySchemes:
    sanctumAuth:
        type: http
        scheme: bearer
```

```php
// config/auth.php — the same name, and nothing in between.
'guards' => [
    'sanctumAuth' => ['driver' => 'sanctum', 'provider' => 'users'],
],
```

This is less explicit than [rule 3](./openapi-support.md#the-four-rules) usually asks for, and it is the one place in
this document where that is a deliberate trade rather than an oversight: the alternative is a second file mapping scheme
names to guard names, one more thing to keep in sync with both the specification and `config/auth.php`, for a
relationship that is already a name in both places. The same reasoning already used
[when `operationId` is absent](./code-generation/generated-file-anatomy.md#when-operationid-is-absent-derive-from-method-and-path)
and for
[factory overrides](./code-generation/response-dtos.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares)
applies here too: match by name first, and only reach for configuration when nomenclature cannot carry the answer.

**The consequence:** a `securitySchemes` name with no guard of the same name is a misconfiguration
[the doctor](#the-doctor-checks-wiring-not-rows) reports, never a silent no-op. A scheme the middleware cannot resolve
to a guard is indistinguishable, at the wire, from a scheme nobody checks.

## One middleware, one question

An operation's own `security` is already read into `Contract\Operation` as a list of schemes and the scopes each one
asks for: [`Partial` in the support matrix](./openapi-support.md#references-and-security), pinned by a test that
distinguishes an inherited requirement from an explicit opt-out. What was missing is what happens with it.

**Decision: exactly one built-in middleware, `final`, and it asks exactly one question, regardless of which guard
authenticated it.** Does the authenticated model have the scope or permission the matched scheme requires? Sanctum,
Passport, a custom guard: the middleware never talks to any of them directly, so it never needs a branch per driver and
never grows one when a fourth guard shows up.

It can stay driver-agnostic because the question it asks is answered by the model, not by the guard. **Decision: the
package defines an interface that the authenticated model implements.** Its working name is `HasSecurityScopes` and it
has one method, `hasSecurityScope(string $scheme, string $scope): bool`. How it answers is entirely yours: call
Sanctum's `tokenCan()`, Passport's `tokenCan()`, a permissions package, a hardcoded rule. The middleware calls the
interface and nothing else, which is the one and only extension point this feature has.

```php
// Shipped by the package. A working name, in the shape Phase 2 will follow.
interface HasSecurityScopes
{
    public function hasSecurityScope(string $scheme, string $scope): bool;
}

// In your own model, where the answer already lives.
public function hasSecurityScope(string $scheme, string $scope): bool
{
    return $this->tokenCan($scope);
}
```

That is narrower than
[the factory override mechanism](./code-generation/response-dtos.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares):
no directory to configure, no scan, no `extends`. The model already exists in every application and is already the one
place that knows how its own scopes work. There is nothing to discover, only an interface to implement.

**Decision: the requirement is resolved into the generated route, not read from the spec at request time.** The scheme,
its matched guard, and the scopes it asks for are baked into the generated route registration as middleware parameters
when `spec:build` runs, the same way every other build-time decision in this package
[never reaches the runtime](./code-generation/index.md#the-runtime-never-sees-the-spec). Wiring the middleware onto the
route is therefore the build's job, not something you add by hand.

**Open:** exactly which `securitySchemes` _types_ reduce to "the model has a scope" and which do not. `apiKey`,
`http bearer` and `oauth2` are the clear fits; `mutualTLS` and the details of `openIdConnect` may not be answerable by
this one middleware at all. A scheme the middleware cannot enforce is a case for
[acknowledgement](./doctor.md#acknowledged-limits-the-consumers-opt-out), not a silent pass.

## Row-level rules are a Policy's job

**Decision: this is the one barrier the package puts up, and nothing finer.** Row-level rules (a user editing their own
comment, a manager approving only their own team's requests) are not enforced by this package, on purpose, and not
because it was too much work: OpenAPI has no vocabulary for them, so honoring them would mean inventing one and reading
it out of `x-` extensions, which is a far bigger commitment than matching a name to a guard.

When an operation needs more than "does the model have this scope", the concrete controller says so with an ordinary
Laravel Policy, exactly where a Policy already belongs. Spec-First does not mean spec-only: the contract stays
authoritative for what it can express, and the application is still where business rules that a specification format was
never designed to carry get written.

## Open: declaring ownership in the spec

One derogation, deliberately not solved yet. "A user may CRUD their own content" is common across enough APIs that
leaving it to a hand-written Policy costs every project the same work. Helping with that, rather than reading the
specification literally, is what separates this package from a generic OpenAPI-to-routes tool.

**Open, and needs validating against a real spec before it is decided:** how ownership gets declared at all. The leading
candidate is an `x-` extension naming the property that must equal the authenticated model's key (`x-owner: userId`, as
a working shape), following the same pattern [`x-audience`, `x-lifecycle` and `x-sunset`](./lifecycle.md) already
established. What it becomes once declared is open too: a scope the built-in middleware understands natively, a stub the
build scaffolds into a Policy, or something else.

This is probably not the only nice-to-have this document ends up owning. The package's goals are DX and being easy for
an AI agent to extend correctly, not reading the specification as literally as possible. Where a gap between what
OpenAPI can say and what almost every API needs shows up often enough, it is a candidate for the package to close, one
deliberate decision at a time.

## The doctor checks wiring, not rows

Once a baseline is enforced by default, the doctor checks:

- **A `securitySchemes` name with no guard of the same name** — the [naming contract](#scheme-names-match-guard-names)
  broken, reported before it becomes a route nobody can pass.
- **A scheme type the built-in middleware cannot enforce at all** — `mutualTLS`, for instance, surfaced the same way any
  other [package limit](./doctor.md#two-kinds-of-finding-never-mixed) is, with acknowledgement as the consumer's way
  past it.
- **An authenticated model missing `HasSecurityScopes`** on a guard a protected operation resolves to. That is a
  misconfiguration rather than a package limit, because the interface is ours to require.

What it does **not** do: flag operations that need row-level authorization. The specification has no way to say that
need exists, so there is nothing in the document for the doctor to read. This document is where that boundary is written
down instead, until [the open ownership question](#open-declaring-ownership-in-the-spec) changes what the specification
can say.
