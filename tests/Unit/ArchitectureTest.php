<?php

declare(strict_types=1);

// Architecture tests enforce the conventions documented in AGENTS.md and
// docs/project/stack.md mechanically, so a review never has to catch them by eye.

arch('source files declare strict types')
    ->expect('Gcob\LaraSpecFirst')
    ->toUseStrictTypes();

arch('no debugging helpers are left behind')
    ->expect(['dd', 'ddd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('service providers extend the Laravel base provider')
    ->expect('Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider');

// The parser is contained. Everything outside Parsing consumes our own types,
// so a future OpenAPI version whose needs cebe cannot meet can bring its own
// parser behind the same strategy interface without anything else noticing —
// and, more immediately, nothing the request path touches can reach it.
//
// Known limit, verified rather than assumed: this assertion reads imports, not
// data. Importing a cebe class outside Parsing does fail it; handing the same
// content across the boundary as a plain array does not. The boundary is only
// as real as the types crossing it, so Parsing must return Contract objects and
// never arrays — and that is worth its own assertion once Contract exists.
//
// See docs/guide/openapi-support.md
arch('the OpenAPI parser stays inside Parsing')
    ->expect('cebe\openapi')
    ->toOnlyBeUsedIn('Gcob\LaraSpecFirst\Parsing');

// Middleware is a method rather than a second mechanism, and Laravel's own
// interface is what makes it one: a project overrides `middleware()` in its own
// subclass with no `implements` clause to remember. Asserted structurally
// because that is what it is — and because writing it as a runtime expectation
// produced a tautology rather than a guard.
//
// See docs/guide/controllers.md — "Middleware is a method, not a separate mechanism"
arch('the generated controllers\' base carries Laravel\'s middleware contract')
    ->expect('Gcob\LaraSpecFirst\Http\Controllers\SpecController')
    ->toImplement('Illuminate\Routing\Controllers\HasMiddleware');

// The runtime never sees a specification. Routing\ is what the service provider
// loads at boot, so it is the one namespace where that promise can be broken by
// a single import — of the reader, of a guard, or of the YAML decoder underneath
// them. The parser is already forbidden here by the rule above; these are the
// rest of the door.
//
// Not a hypothetical: writing `{@see}` at a class in another namespace is enough
// for the formatter to turn it into a real import, which is how the comment
// below came to be written.
//
// See docs/guide/code-generation.md — "The runtime never sees the spec"
arch('routing at boot cannot reach a specification')
    ->expect('Gcob\LaraSpecFirst\Routing')
    ->not->toUse([
        'Gcob\LaraSpecFirst\Parsing',
        'Symfony\Component\Yaml',
    ]);

// Contract\ is what every other layer consumes, so it must know nothing about
// the layer that produces it. The inversion is easy to introduce by accident —
// a `{@see}` in a docblock is enough to add the import — and impossible to see
// in a diff once it is there.
//
// Written when three of the four namespaces below did not exist, which cost
// nothing and meant the rule was in place the day they arrived. All four exist
// now, and `Generation\` and `Console\` arrived under it.
arch('the contract knows nothing about how it was produced')
    ->expect('Gcob\LaraSpecFirst\Contract')
    ->not->toUse([
        'Gcob\LaraSpecFirst\Parsing',
        'Gcob\LaraSpecFirst\Generation',
        'Gcob\LaraSpecFirst\Console',
        'Gcob\LaraSpecFirst\Routing',
        'Gcob\LaraSpecFirst\Scaffolding',
    ]);

// The invariant this package refuses to make conditional: **the build never creates
// a class a developer will own.** It has been true because `spec:build` happens not
// to call the scaffolder, which is a fact about today's code rather than a rule —
// and the one flag or convenience that changed it would be a diff nobody would read
// as a violation. `Scaffolding\` is where the writing of owned files lives, so
// forbidding the import says it structurally.
//
// The reverse direction is deliberately allowed: `spec:make` reads names and
// pointers out of `Generation\`, because the class it scaffolds has to agree with
// the parent the build emits about what both are called.
//
// See docs/guide/code-generation.md — "Scaffolding is spec:make, not a build step"
// Written as two assertions rather than one over a list of namespaces, because the
// list form does not do what it reads like: `expect([A, B])->not->toUse(C)` passed
// against a `Generation\` class that really did import `Scaffolding\`. Verified by
// introducing that import on purpose — a rule nobody has watched fail is a rule
// nobody should trust.
arch('the build cannot scaffold a file a developer will own')
    ->expect('Gcob\LaraSpecFirst\Generation')
    ->not->toUse('Gcob\LaraSpecFirst\Scaffolding');

arch('routing at boot cannot scaffold either')
    ->expect('Gcob\LaraSpecFirst\Routing')
    ->not->toUse('Gcob\LaraSpecFirst\Scaffolding');

// `spec:build` is the command the invariant above is really about — the one place
// where a flag or a convenience could quietly turn "the build never scaffolds" into
// "the build scaffolds when nobody's looking." `Console\` as a whole cannot carry
// this rule: `spec:make` is `Console\MakeCommand`, and scaffolding is its entire job.
// So the assertion is written against `BuildCommand` by name rather than against the
// namespace — precise about the one class the invariant cannot survive an import
// into, without forbidding the command that is supposed to import it.
//
// This only holds because `OperationSelector` (which `spec:build` legitimately uses,
// to print operations by tag) lives in `Generation\` rather than in `Scaffolding\` —
// it selects operations and writes nothing, so it was never the kind of class this
// rule is about.
arch('the build command cannot scaffold a file a developer will own')
    ->expect('Gcob\LaraSpecFirst\Console\BuildCommand')
    ->not->toUse('Gcob\LaraSpecFirst\Scaffolding');

// **The doctor reads, it never writes.** `DoctorCommand`'s own docblock claims
// it in bold and doctor.md makes it a contract bullet — "read-only, always" —
// which until now was a fact about what the code happened to call rather than a
// rule. It is the one property that makes the command safe to point at
// production's checkout, and the diff that broke it would be one convenience
// away: a section that "fixes" drift, a cache written to speed a second run.
//
// Written as the writers themselves rather than as a namespace ban, because
// `Doctor\` legitimately depends on `Generation\` — it plans exactly as the
// build does, `GeneratedTree::diff()` included, and diffing is how it answers
// drift at all. What it may never reach is the writing half.
arch('the doctor never writes anything')
    ->expect('Gcob\LaraSpecFirst\Doctor')
    ->not->toUse([
        'file_put_contents',
        'unlink',
        'rmdir',
        'mkdir',
        'rename',
        'fopen',
        'touch',
        'copy',
    ]);

// And it never scaffolds either, for the same reason `Generation\` and
// `Routing\` may not: `Scaffolding\` is where writing a file a developer will
// own lives, so forbidding the import says structurally what "read-only" means.
arch('the doctor cannot scaffold a file a developer will own')
    ->expect('Gcob\LaraSpecFirst\Doctor')
    ->not->toUse('Gcob\LaraSpecFirst\Scaffolding');

// The dependency direction the planning document for the doctor specified:
// `Doctor\` may depend on `Parsing\`, `Contract\`, `Generation\` and
// `Routing\`, never the reverse. Written from the other side — none of those
// four may import `Doctor\` — because that is the direction a `{@see}` in a
// docblock introduces by accident, and the one impossible to spot in a diff
// once it is there.
arch('nothing the doctor reads knows the doctor exists')
    ->expect('Gcob\LaraSpecFirst\Parsing')
    ->not->toUse('Gcob\LaraSpecFirst\Doctor');

arch('the contract knows nothing about the doctor either')
    ->expect('Gcob\LaraSpecFirst\Contract')
    ->not->toUse('Gcob\LaraSpecFirst\Doctor');

arch('generation knows nothing about the doctor')
    ->expect('Gcob\LaraSpecFirst\Generation')
    ->not->toUse('Gcob\LaraSpecFirst\Doctor');

arch('routing at boot knows nothing about the doctor')
    ->expect('Gcob\LaraSpecFirst\Routing')
    ->not->toUse('Gcob\LaraSpecFirst\Doctor');
