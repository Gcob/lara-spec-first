<?php

declare(strict_types=1);

// Architecture tests enforce the conventions documented in AGENTS.md and
// docs/STACK.md mechanically, so a review never has to catch them by eye.

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
// See docs/OPENAPI-SUPPORT.md
arch('the OpenAPI parser stays inside Parsing')
    ->expect('cebe\openapi')
    ->toOnlyBeUsedIn('Gcob\LaraSpecFirst\Parsing');

// Contract\ is what every other layer consumes, so it must know nothing about
// the layer that produces it. The inversion is easy to introduce by accident —
// a `{@see}` in a docblock is enough to add the import — and impossible to see
// in a diff once it is there.
arch('the contract knows nothing about how it was produced')
    ->expect('Gcob\LaraSpecFirst\Contract')
    ->not->toUse([
        'Gcob\LaraSpecFirst\Parsing',
        'Gcob\LaraSpecFirst\Generation',
        'Gcob\LaraSpecFirst\Console',
        'Gcob\LaraSpecFirst\Routing',
    ]);
