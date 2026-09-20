<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;

// The equivalence class this suite was missing: a schema construct that would
// be read *wrongly* rather than not at all.
//
// The support matrix puts almost every keyword the parser hands back raw at
// Ignored, because nothing reading it costs a feature and no more. Four shapes
// are different, and they sit at Rejected for one reason each time: the package
// would come back with a value, and the value would be wrong. That is the one
// outcome rule 2 forbids, and it is the outcome nothing detects later, since
// nothing fails.
//
// Partitioned by what goes wrong rather than by keyword. Eleven keywords share
// the first class and one case covers them, because the mechanism is identical
// and eleven fixtures would be eleven copies of one argument.
//
// @see docs/guide/openapi-support.md — "Schemas"
// @see AGENTS.md — "Automated tests are required"

// Class one: a keyword whose value is a schema, handed back raw, with a
// reference inside it the parser never resolved.
it('refuses a raw keyword hiding an unresolved reference', function (): void {
    expect(fn () => extractFixture('prefix-items-hides-a-ref.yaml'))
        ->toThrow(RejectedConstructException::class, 'writes `prefixItems`');
});

// The message has to name the keyword and where the reference points, because
// neither is visible in the generated output the author would otherwise be
// looking at: there is none, and that is the whole problem.
it('names the keyword and the reference it found', function (): void {
    expect(fn () => extractFixture('prefix-items-hides-a-ref.yaml'))
        ->toThrow(RejectedConstructException::class, '#/components/schemas/Amount');
});

// The other side of the same class, and the one a name-based rule gets wrong: a
// `$ref` written inside data is a literal. An API that itself deals in JSON
// Schema writes exactly this, and refusing it would turn away a valid contract.
it('accepts the same keyword when the reference is data', function (): void {
    expect(extractFixture('raw-keyword-holds-data.yaml'))->toHaveCount(1);
});

// Class two: a keyword that changes how every reference under it resolves.
// Ignoring it would send a reference to a target the document never named.
it('refuses a schema that rebases its own references', function (): void {
    expect(fn () => extractFixture('schema-id.yaml'))
        ->toThrow(RejectedConstructException::class, 'declares `$id`');
});

// Class three: dynamic-scope resolution, which this package does not do. The
// message says which of the two the author probably meant, because a recursive
// schema is what people reach for these by mistake and it is supported.
it('refuses a dynamic reference and names what was probably meant', function (): void {
    expect(fn () => extractFixture('dynamic-reference.yaml'))
        ->toThrow(RejectedConstructException::class, 'referring back to itself');
});

// Class four: a reference aimed *into* a `$defs`. The keyword itself stays
// ignored, since a definition kept there is only invisible. Aiming at one is
// what turns invisible into wrong.
it('refuses a reference aimed at a position inside a $defs', function (): void {
    expect(fn () => extractFixture('ref-into-defs.yaml'))
        ->toThrow(RejectedConstructException::class, 'inside a `$defs`');
});
