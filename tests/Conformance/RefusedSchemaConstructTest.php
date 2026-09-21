<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Symfony\Component\Yaml\Yaml;

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

/**
 * Read a 3.1 document whose body schema writes one raw keyword over inline
 * schemas.
 *
 * Built in a temporary directory rather than kept beside the other fixtures:
 * eleven near-identical files would bury the ten that say something, and what
 * changes between these is one key. The read itself goes through the helper in
 * `tests/Pest.php`, so the "throw the first fault" convention every `toThrow()`
 * in this suite reads against has one definition rather than two.
 *
 * @return list<Operation>
 */
function extractInlineRawKeyword(string $keyword, mixed $value): array
{
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Fixture API', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'post' => [
                    'operationId' => 'createThing',
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'object', $keyword => $value],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
    ];

    // Named rather than asked for: `tempnam()` creates the file it names, and
    // appending an extension to that name leaves the original behind on every
    // run. The rest of the suite names its own path for the same reason.
    $path = sys_get_temp_dir().'/lsf-raw-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($path, Yaml::dump($document, 10));

    try {
        return extractDocumentAt($path);
    } finally {
        unlink($path);
    }
}

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

// Eleven keywords share the first class and one fixture covers the refusal,
// because the mechanism is identical and eleven documents would be eleven copies
// of one argument. The negative side is per keyword, and it has to be: what a
// shared fixture cannot catch is one entry of SCHEMA_CARRYING_RAW_KEYWORDS
// matching too eagerly, which would refuse a document holding nothing but
// inline schemas.
it('accepts a schema-carrying keyword that holds no reference', function (string $keyword, mixed $value): void {
    expect(extractInlineRawKeyword($keyword, $value))->toHaveCount(1);
})->with([
    'prefixItems' => ['prefixItems', [['type' => 'string']]],
    'contains' => ['contains', ['type' => 'string']],
    'unevaluatedItems' => ['unevaluatedItems', ['type' => 'string']],
    'patternProperties' => ['patternProperties', ['^x-' => ['type' => 'string']]],
    'propertyNames' => ['propertyNames', ['type' => 'string']],
    'dependentSchemas' => ['dependentSchemas', ['card' => ['type' => 'object']]],
    'unevaluatedProperties' => ['unevaluatedProperties', ['type' => 'string']],
    'contentSchema' => ['contentSchema', ['type' => 'object']],
    'if' => ['if', ['type' => 'object']],
    'then' => ['then', ['type' => 'object']],
    'else' => ['else', ['type' => 'object']],
]);

// A reference whose *fragment* is ordinary and whose file path happens to carry
// a `$defs` segment. Contrived, and what is at stake is the message rather than
// the refusal: telling an author to move a definition that is not where the
// message says it is would be worse than saying nothing.
it('does not read a $defs in a file path as a $defs in a document', function (): void {
    expect(extractFixture('defs-in-a-file-path.yaml'))->toHaveCount(1);
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
it('refuses a reference aimed at a $defs', function (string $fixture): void {
    expect(fn () => extractFixture($fixture))
        ->toThrow(RejectedConstructException::class, 'inside a `$defs`');
})->with([
    'at something inside it' => ['ref-into-defs.yaml'],
    // The container itself, which resolves to a plain array exactly as a
    // pointer one segment deeper does. It was accepted until the refusal
    // learned to read the last segment too, and what it cost was a property
    // vanishing with nothing said: the expensive shape of this bug is that
    // nothing fails.
    'at the container itself' => ['ref-at-the-defs-container.yaml'],
]);

// A schema is a schema wherever the document puts it. The refusals above are
// written against request bodies because that is where the caveat was first
// met; this is the case that stops them from quietly being a request-side rule.
it('refuses the same construct inside a response', function (): void {
    expect(fn () => extractFixture('refused-in-a-response.yaml'))
        ->toThrow(RejectedConstructException::class, 'writes `prefixItems`');
});