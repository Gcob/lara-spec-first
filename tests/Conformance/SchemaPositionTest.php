<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Parsing\DocumentWalk;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\SchemaPosition;
use Symfony\Component\Yaml\Yaml;

// The equivalence class this suite was missing: *where* a schema is, as opposed
// to what it says. Partitioned by where the definition is written relative to
// the reference that reaches it, because that is the only axis the old answer
// — `cebe\openapi\`'s `getDocumentPosition()` — got wrong.
//
// Three readings share one position and therefore one defect, which is why one
// file covers all three: a refusal names it, a recursion marker names it, and a
// schema's name is derived from it. A probe run against the real parser on 20
// September 2026 measured every row below before any of this was built; these
// are that probe, kept.
//
// @see docs/guide/openapi-support.md — "Where a schema is reported from"
// @see AGENTS.md — "Automated tests are required"

/**
 * Every operation of a fixture, keyed by `operationId`.
 *
 * @return array<string, Operation>
 */
function operationsById(string $fixture): array
{
    $byId = [];

    foreach (extractFixture($fixture) as $operation) {
        $byId[(string) $operation->operationId] = $operation;
    }

    return $byId;
}

/**
 * The `application/json` body schema of one named operation.
 */
function bodyOf(string $fixture, string $operationId): Schema
{
    $body = operationsById($fixture)[$operationId]->requestBody;

    expect($body)->not->toBeNull();
    assert($body !== null);

    return $body->content['application/json'];
}

// Class one: a refusal raised on a schema written in another file. The message
// is a user's only way to find the offending line, and before the raw walk it
// named a pointer into the root document, which has nothing at it.
it('names the file a refused schema is actually written in', function (): void {
    expect(fn () => extractFixture('external-refusal/main.yaml'))
        ->toThrow(
            RejectedConstructException::class,
            'The schema at "other.yaml#/components/schemas/Pet/properties/tag" declares `$id`'
        );
});

// The local case is the control: a single-file contract's messages did not
// change, which is what says the correction is a correction and not a rewrite.
it('leaves a refusal in the root document spelled as it always was', function (): void {
    expect(fn () => extractFixture('schema-id.yaml'))
        ->toThrow(RejectedConstructException::class, 'The schema at "#/');
});

// Class two: recursion through a schema written in another file. The parser
// hands back a *copy* of a resolved external node, so an identity check finds
// two objects where the document wrote one definition and cuts one level too
// late — `children[].children[]` instead of `children[]`.
it('cuts an external recursive schema at the first level', function (): void {
    $node = bodyOf('external-recursion/main.yaml', 'createNode');
    $children = $node->properties['children'];

    expect($children->items?->recursesTo)->toBe('node.yaml#/components/schemas/Node')
        ->and($children->items?->properties)->toBe([])
        // The marker knows the position, so it knows the name: a field that
        // meant something on every node but this one would be a special case.
        ->and($children->items?->name)->toBe('Node');
});

// The same shape in one file, which already worked and has to keep working.
it('keeps cutting a local recursive schema where it always did', function (): void {
    $children = bodyOf('recursive-schema-in-body.yaml', 'createNode')->properties['children'];

    expect($children->items?->recursesTo)->toBe('#/components/schemas/Node')
        ->and($children->items?->name)->toBe('Node');
});

// Class three: the name a schema carries. Decision 7 of the card #35 plan —
// the name comes from the schema the reference lands on, never from the file
// holding it and never from how the reference was spelled.
it('names a schema after the component it resolves to', function (
    string $operationId,
    ?string $expected,
): void {
    expect(bodyOf('schema-names/main.yaml', $operationId)->name)->toBe($expected);
})->with([
    'a local component' => ['createLocal', 'LocalPet'],
    'a component in another file' => ['createExternal', 'Pet'],
    'a local alias of an external component' => ['createThroughAlias', 'Pet'],
    'a component in a subdirectory' => ['createFromASubdirectory', 'Wrapper'],
    'a schema that is a whole file' => ['createWholeFile', 'Address'],
    'a file whose name is no identifier' => ['createUnnameableFile', null],
    'a schema written inline' => ['createInline', null],
]);

// The claim the three rows above only imply together, asserted on its own
// because it is the one decision 7 exists for: splitting a specification into
// files, or writing a reference a different way, renames nothing.
it('gives one name to three spellings of one schema', function (): void {
    $names = [
        bodyOf('schema-names/main.yaml', 'createExternal')->name,
        bodyOf('schema-names/main.yaml', 'createThroughAlias')->name,
        bodyOf('schema-names/main.yaml', 'createFromASubdirectory')->properties['pet']->name,
    ];

    expect(array_unique($names))->toBe(['Pet']);
});

// A body is not the only schema the extractor walks, and the other two reach
// their position by a different route: a response through its status code, and
// a query parameter through an index into a list the extractor merged. The
// index is the one worth asserting rather than reading — the parameter loop was
// split in two so that each raw index stays available, and an off-by-one there
// names another parameter's schema instead of failing.
it('names a schema reached through a response', function (): void {
    $response = operationsById('schema-names/main.yaml')['listThings']->responses[0];

    expect($response->status)->toBe('200')
        ->and($response->content['application/json']->name)->toBe('Pet');
});

it('names a schema reached through a query parameter', function (
    string $parameter,
    string $expected,
): void {
    $parameters = [];

    foreach (operationsById('schema-names/main.yaml')['listThings']->queryParameters as $declared) {
        $parameters[$declared->name] = $declared;
    }

    expect($parameters[$parameter]->schema->name)->toBe($expected);
})->with([
    // Written second, behind a `header` parameter the extractor skips, so a
    // position taken from the filtered list rather than from the document
    // would land on the header's schema.
    'written on the operation, behind a parameter that is not a query one' => ['page', 'Page'],
    // Written on the Path Item, which is a different base position entirely.
    'written on the Path Item' => ['tenant', 'Tenant'],
]);

// The guard for risk 6 of the plan. The raw walk follows `$ref` itself rather
// than asking the parser, so the two could drift — a relative path resolved
// against a different directory, say — and the failure would be a silently
// wrong position rather than an error. Nothing else would catch it, so the two
// answers are compared node by node over a contract split across files,
// subdirectories and an alias.
it('lands on the same schema the parser resolved, at every node', function (): void {
    $path = specFixturePath('schema-names/main.yaml');
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile($path);
    $walk = new DocumentWalk($path, $document);

    $paths = $walk->child($walk->root(), 'paths');
    $compared = 0;

    foreach (extractFixture('schema-names/main.yaml') as $operation) {
        $template = $operation->path->template;
        $pathItemAt = $walk->child($paths, $template);
        $at = $walk->child($pathItemAt, $operation->method->value);

        foreach ($operation->requestBody->content ?? [] as $mediaType => $schema) {
            $compared += compareRawAgainstResolved($schema, $walk->child($walk->child($walk->child(
                $walk->child($at, 'requestBody'), 'content'), $mediaType), 'schema'), $walk);
        }

        foreach ($operation->responses as $response) {
            foreach ($response->content as $mediaType => $schema) {
                $compared += compareRawAgainstResolved($schema, $walk->child($walk->child($walk->child($walk->child(
                    $walk->child($at, 'responses'), $response->status), 'content'), $mediaType), 'schema'), $walk);
            }
        }
    }

    // Asserted so that a walk which silently stopped at the root cannot pass by
    // comparing nothing. Eight bodies and a response, each at least one node
    // deep, and the two schemas that are whole files.
    expect($compared)->toBeGreaterThanOrEqual(15);
});

/**
 * Assert that the raw schema written at a computed position says what the
 * resolved schema beside it says, and do it again for every child.
 *
 * Compared on the two properties a reader can check without reimplementing
 * normalization: the type, and the set of property names. A position pointing
 * at the wrong node in a contract of distinct schemas fails one or the other.
 *
 * @return int how many nodes were compared
 */
function compareRawAgainstResolved(Schema $resolved, SchemaPosition $at, DocumentWalk $walk): int
{
    // The one node with nothing to compare: the walk stopped, and what is at
    // the other end was already compared as the ancestor it points back at.
    if ($resolved->recursesTo !== null) {
        return 0;
    }

    $raw = $walk->rawAt($at);

    expect($raw)->not->toBeNull("nothing is written at {$walk->pointer($at)}");
    assert(is_array($raw));

    $type = $resolved->soleType();

    expect($raw['type'] ?? null)->toBe($type?->value, "type differs at {$walk->pointer($at)}")
        ->and(array_keys(is_array($raw['properties'] ?? null) ? $raw['properties'] : []))
        ->toBe(array_keys($resolved->properties), "properties differ at {$walk->pointer($at)}");

    $compared = 1;
    $propertiesAt = $walk->child($at, 'properties');

    foreach ($resolved->properties as $name => $property) {
        $compared += compareRawAgainstResolved($property, $walk->child($propertiesAt, (string) $name), $walk);
    }

    if ($resolved->items !== null) {
        $compared += compareRawAgainstResolved($resolved->items, $walk->child($at, 'items'), $walk);
    }

    return $compared;
}

// Nothing above exercises a type list, and the comparison helper reads
// `soleType()`, so the fixture's own shape is pinned: a row that stopped being
// an object would make the guard above pass for the wrong reason.
it('reads the compared fixture as the object schemas it is', function (): void {
    expect(bodyOf('schema-names/main.yaml', 'createFromASubdirectory')->types)
        ->toBe([SchemaType::Object]);
});
