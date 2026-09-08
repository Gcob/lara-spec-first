<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;

// The distinction this class exists to make. If only one test survives a
// refactor, it should be this pair.

it('accepts a schema that refers back to itself through content', function (): void {
    expect((new ReferenceCycleDetector)->findCycles(specFixture('recursive-schema.yaml')))->toBe([]);
});

it('rejects a chain of references that never reaches content', function (): void {
    $faults = (new ReferenceCycleDetector)->findCycles(specFixture('cycle-pointer.yaml'));

    expect($faults)->toHaveCount(1)
        ->and($faults[0])->toBeInstanceOf(CyclicReferenceException::class);
});

it('names the whole chain so the cycle can be found', function (): void {
    $faults = (new ReferenceCycleDetector)->findCycles(specFixture('cycle-pointer.yaml'));

    expect($faults[0]->getMessage())->toContain(
        '#/components/schemas/A -> #/components/schemas/B -> #/components/schemas/A'
    );
});

it('rejects a reference to itself', function (): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => '#/components/schemas/A']]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

it('rejects a longer cycle', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['$ref' => '#/components/schemas/A'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

it('accepts a chain of references that does reach content', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['type' => 'string'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
});

// Anything needing the vendored copies is out of reach until they are loaded.
// Stated as a test so the limit is visible rather than implied.
it('ignores references it cannot resolve without the vendored copies', function (mixed $ref): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => $ref]]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
})->with([
    'another file' => ['common.yaml#/components/schemas/A'],
    'a URL' => ['https://example.com/schemas.yaml#/A'],
    'not a string' => [42],
]);

it('walks path templates without mistaking them for pointer segments', function (): void {
    $document = ['paths' => ['/users/{id}' => ['get' => ['responses' => ['200' => [
        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/A']]],
    ]]]]], 'components' => ['schemas' => ['A' => ['$ref' => '#/paths/~1users~1{id}/get/responses/200/content/application~1json/schema']]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

it('accepts the documents that carry no cycle', function (string $name): void {
    expect((new ReferenceCycleDetector)->findCycles(specFixture($name)))->toBe([]);
})->with([
    'openapi-3.0.yaml',
    'openapi-3.1.yaml',
    'openapi-3.1-webhooks-only.yaml',
]);

// `$ref` is a legal key name inside a value. An API that itself handles JSON
// Schema will carry one in an example, and refusing to load such a document
// would be the worst failure this class can produce — a valid contract turned
// away, where a false negative would merely leave the parser to complain.
//
// Written as a literal pointing at the position it occupies itself: walked, it
// would close a cycle on the spot, so the assertion cannot pass by accident.
it('does not read a literal $ref inside a value as a reference', function (string $key): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', $key => ['$ref' => '#/components/schemas/A/'.$key]],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
})->with(['example', 'default', 'enum', 'const']);

// --- The other half of that boundary: a literal stays a literal only until a
// Reference Object aims at the position holding it. The parser resolves that
// pointer against the tree it has already built, finds the `$ref` sitting in
// the data and follows it, which is how the same memory exhaustion a pure cycle
// causes is reached from a shape no rule about key names can see. ---

it('follows a reference whose pointer lands inside a data-carrying key', function (string $key): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', $key => ['$ref' => '#/components/schemas/B']],
        'B' => ['$ref' => '#/components/schemas/A/'.$key],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
})->with(['example', 'default', 'enum', 'const']);

it('catches the same shape in each of the three fixtures that spell it', function (string $fixture): void {
    expect((new ReferenceCycleDetector)->findCycles(specFixture($fixture)))->toHaveCount(1);
})->with([
    'a pointer into a schema\'s example' => ['ref-inside-example.yaml'],
    'a pointer into an Example Object\'s value' => ['example-object-value.yaml'],
    'a pointer into a JSON Schema examples list' => ['schema-examples-list.yaml'],
]);

// Following a target into data reports a cycle, never a chain: data a reference
// legitimately points through still has to be allowed to reach a schema.
it('accepts a reference that lands inside data and does reach content', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', 'example' => ['$ref' => '#/components/schemas/C']],
        'B' => ['$ref' => '#/components/schemas/A/example'],
        'C' => ['type' => 'string'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
});

// A target inside data may itself point into more data, so the walk repeats
// rather than resolving one step and stopping.
it('follows a chain that crosses data more than once', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', 'example' => ['$ref' => '#/components/schemas/B/example']],
        'B' => ['type' => 'object', 'example' => ['$ref' => '#/components/schemas/A/example']],
        'C' => ['$ref' => '#/components/schemas/A/example'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

// `examples` is two different things wearing one name, and only its shape tells
// them apart. Both directions matter, so both are asserted: reading the OpenAPI
// map as data would hide a cycle on exactly the shape the parser dies on.
it('ignores a $ref inside the JSON Schema examples keyword, which is a list', function (): void {
    expect((new ReferenceCycleDetector)->findCycles(specFixture('examples-list-is-data.yaml')))->toBe([]);
});

it('still catches a cycle through OpenAPI Example Objects, which are a map', function (): void {
    expect((new ReferenceCycleDetector)->findCycles(specFixture('cycle-in-example-objects.yaml')))->toHaveCount(1);
});

// Stated so the gap is visible: `follow()` compares pointers for equality, not
// containment, so a reference aimed at one of its own ancestors is not caught.
// A false negative, which leaves the parser to fail rather than refusing a
// document that was fine.
it('does not catch a reference aimed at its own ancestor', function (): void {
    $document = ['components' => ['schemas' => ['A' => ['$ref' => '#/components/schemas']]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
});

// The map of Example Objects is followed, because any of them may be a
// Reference Object — but each object's `value` is literal data. `value` cannot
// join the opaque list, since `properties: {value: {...}}` is an ordinary
// schema; only its position inside an Example Object makes it data.
it('does not follow the value of an Example Object', function (): void {
    $document = ['components' => ['examples' => [
        'A' => ['value' => ['$ref' => '#/components/examples/A/value']],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
});

it('still follows an Example Object that is itself a reference', function (): void {
    $document = ['components' => ['examples' => [
        'A' => ['$ref' => '#/components/examples/B'],
        'B' => ['$ref' => '#/components/examples/A'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

// The opaque list reasons about key names, never about positions, so a schema
// property genuinely named like one of them is not walked into. Aiming a
// reference at it is caught all the same, because that no longer depends on the
// name the position was reached through.
it('still catches a reference aimed at a schema property named like a data-carrying key', function (string $name): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', 'properties' => [$name => ['$ref' => '#/components/schemas/B']]],
        'B' => ['$ref' => '#/components/schemas/A/properties/'.$name],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
})->with(['default', 'example', 'enum', 'const']);

// What the name-based rule still misses, and it is worth stating rather than
// implying: a cycle closing entirely inside a schema property named like a
// data-carrying key, with nothing pointing at it from outside. Nothing collects
// it and no target reaches it. A false negative, which leaves the parser to
// fail rather than refusing a document that was fine.
it('does not catch a cycle closing only inside a schema property named like a data-carrying key', function (string $name): void {
    $document = ['components' => ['schemas' => [
        'A' => ['type' => 'object', 'properties' => [$name => ['$ref' => '#/components/schemas/A/properties/'.$name]]],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toBe([]);
})->with(['default', 'example', 'enum', 'const']);

// --- Collecting more than one fault in a single pass, the reason this class
// stopped throwing at the first cycle it found. ---

it('reports two independent cycles as two faults', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/A'],
        'X' => ['$ref' => '#/components/schemas/Y'],
        'Y' => ['$ref' => '#/components/schemas/X'],
    ]]];

    $faults = (new ReferenceCycleDetector)->findCycles($document);

    expect($faults)->toHaveCount(2);

    $messages = implode('', array_map(
        static fn (CyclicReferenceException $fault): string => $fault->getMessage(),
        $faults,
    ));

    expect($messages)->toContain('#/components/schemas/A -> #/components/schemas/B -> #/components/schemas/A')
        ->and($messages)->toContain('#/components/schemas/X -> #/components/schemas/Y -> #/components/schemas/X');
});

// Two starting points on the very same cycle must not be counted twice: the
// document has one broken chain, not one per pointer that happens to sit on it.
it('reports one cycle once, however many pointers sit on it', function (): void {
    $document = ['components' => ['schemas' => [
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['$ref' => '#/components/schemas/A'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});

// A reference that leads into a cycle without being part of it must not be
// reported as a second, distinct fault — it is the same broken chain, seen
// from one step further back.
it('does not report a second fault for a reference that only leads into an already-found cycle', function (): void {
    $document = ['components' => ['schemas' => [
        'D' => ['$ref' => '#/components/schemas/A'],
        'A' => ['$ref' => '#/components/schemas/B'],
        'B' => ['$ref' => '#/components/schemas/C'],
        'C' => ['$ref' => '#/components/schemas/A'],
    ]]];

    expect((new ReferenceCycleDetector)->findCycles($document))->toHaveCount(1);
});
