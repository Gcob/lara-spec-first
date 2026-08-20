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

OpenAPI can say an operation requires `bearerAuth` with scope `write`. It cannot say anything about the row being
written — whether the caller may touch _this_ record — because the specification has no vocabulary for it. This document
owns the line between the two: what the package enforces because the contract actually says it, and what it deliberately
leaves to the application because the contract cannot.

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

## Scheme names are a naming contract with your guards

**Decision: a `securitySchemes` name matches a Laravel guard by nomenclature, not by a config mapping written by hand.**
A scheme named `sanctumAuth` in the specification is expected to name a guard called `sanctumAuth` in `config/auth.php`.
No new config key sits between the two.

This is less explicit than [rule 3](./openapi-support.md#the-four-rules) usually asks for, and it is the one place in
this document where that is a deliberate trade rather than an oversight: the alternative is a second file mapping scheme
names to guard names, one more thing to keep in sync with both the specification and `config/auth.php`, for a
relationship that is already a name in both places. The same reasoning already used
[when `operationId` is absent](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path) and for
[factory overrides](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares) applies
here too: match by name first, and only reach for configuration when nomenclature genuinely cannot carry the answer.

**The consequence:** a `securitySchemes` name with no guard of the same name is not a silent no-op — it is a
misconfiguration [the doctor](#the-doctors-role) reports, because a scheme the middleware cannot resolve to a guard is
indistinguishable, at the wire, from a scheme nobody checks.

## One middleware, one question: does the model have the scope

An operation's own `security` is already read into `Contract\Operation` as a list of schemes and the scopes each one
asks for — [`Partial` in the support matrix](./openapi-support.md#references-and-security), and pinned by a test that
distinguishes an inherited requirement from an explicit opt-out. What was missing is what happens with it.

**Decision: exactly one built-in middleware, `final`, and it asks exactly one question** — does the authenticated model
have the scope or permission the matched scheme requires — **regardless of which guard authenticated it.** Sanctum,
Passport, a custom guard: the middleware never talks to any of them directly, so it never needs a branch per driver and
never grows one when a fourth guard shows up.

It can stay driver-agnostic because the question it asks is answered by the model, not by the guard. **Decision: the
package defines an interface** — working name `HasSecurityScopes`, one method,
`hasSecurityScope(string $scheme, string $scope): bool` — **that the authenticated model implements.** How that method
answers is entirely the application's business: call Sanctum's `tokenCan()`, Passport's `tokenCan()`, a permissions
package, a hardcoded rule. The middleware calls the interface and nothing else, which is the one and only extension
point this feature has.

That is narrower than
[the factory override mechanism](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares):
no directory to configure, no scan, no `extends`. The model already exists in every application and is already the one
place that knows how its own scopes work — there is nothing to discover, only an interface to implement.

**Decision: the requirement is resolved into the generated route, not read from the spec at request time.** The scheme,
its matched guard, and the scopes it asks for are baked into the generated route registration as middleware parameters
when `spec:build` runs, the same way every other build-time decision in this package
[never reaches the runtime](./code-generation.md#the-runtime-never-sees-the-spec). Wiring the middleware onto the route
is therefore the build's job, not something a developer adds by hand.

**Open:** exactly which `securitySchemes` _types_ reduce to "the model has a scope" and which do not — `apiKey`,
`http bearer` and `oauth2` are the clear fits; `mutualTLS` and the details of `openIdConnect` may not be answerable by
this one middleware at all. A scheme the middleware cannot enforce is a case for
[acknowledgement](./doctor.md#acknowledged-limits-the-consumers-opt-out), not a silent pass.

## Past the scope check, it is a Policy's job

**Decision: this is the one barrier the package puts up, and nothing finer.** Row-level rules — a user editing their own
comment, a manager approving only their own team's requests — are not enforced by this package, on purpose, and not
because it was too much work: OpenAPI has no vocabulary for them, so honoring them would mean inventing one and reading
it out of `x-` extensions, which is a far bigger commitment than matching a name to a guard.

When an operation needs more than "does the model have this scope", the concrete controller says so with an ordinary
Laravel Policy, exactly where a Policy already belongs. Spec-First does not mean spec-only: the contract stays
authoritative for what it can express, and the application is still where business rules that a specification format was
never designed to carry get written.

## Open: saying "a user's own content" in the spec

One derogation, deliberately not solved yet: "a user may CRUD their own content" is common enough, across enough APIs,
that leaving it entirely to a hand-written Policy in every project would be leaving DX on the table for something the
package could plausibly help with — and helping with it, not merely reading the spec literally, is the whole point of
this package existing rather than a generic OpenAPI-to-routes tool.

**Open, and needs validating against a real spec before it is decided:** how ownership gets declared at all — an `x-`
extension naming the property that must equal the authenticated model's key (`x-owner: userId`, as a working shape) is
the leading candidate, following the same pattern [`x-audience`, `x-lifecycle` and `x-sunset`](./lifecycle.md) already
established — and, once declared, whether it becomes a scope the built-in middleware understands natively, a stub the
build scaffolds into a Policy, or something else entirely.

This is very likely not the only nice-to-have this document ends up owning. The package's goals are DX and being easy
for an AI agent to extend correctly, not reading the specification as literally as possible — where a real gap between
"what OpenAPI can say" and "what almost every API actually needs" shows up often enough, it is a candidate for the
package to close, one deliberate decision at a time, not a reason to stay purist about the spec's own vocabulary.

## The doctor's role

Once a baseline is enforced by default, the doctor's job past that changes shape. What it checks, concretely:

- **A `securitySchemes` name with no guard of the same name** — the
  [naming contract](#scheme-names-are-a-naming-contract-with-your-guards) broken, reported before it becomes a route
  nobody can pass.
- **A scheme type the built-in middleware cannot enforce at all** — `mutualTLS`, for instance — surfaced the same way
  any other [package limit](./doctor.md#two-kinds-of-finding-never-mixed) is, with
  [acknowledgement](./doctor.md#acknowledged-limits-the-consumers-opt-out) as the consumer's way past it.
- **An authenticated model missing `HasSecurityScopes`** on a guard a protected operation resolves to — a
  misconfiguration, not a package limit, because the interface is ours to require.

What it does **not** do: flag operations that need row-level authorization. The specification has no way to say that
need exists, so there is nothing in the document for the doctor to read — this document is where that boundary is
written down instead, until [the open ownership question](#open-saying-a-users-own-content-in-the-spec) changes what the
specification can say.
