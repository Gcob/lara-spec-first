<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\DocumentWalk;
use Gcob\LaraSpecFirst\Parsing\SchemaPosition;
use Symfony\Component\Yaml\Yaml;

// The raw walk on its own, away from the extractor that threads it through a
// document. What is pinned here is the walk's own rules — how a reference is
// followed, how a position is spelled, and what counts as a name — because
// each of them is a decision the parser used to make wrongly on this package's
// behalf.
//
// @see tests/Conformance/SchemaPositionTest.php for the same rules end to end
// @see docs/guide/openapi-support.md — "Where a schema is reported from"

/**
 * A walk over the multi-file fixture every case below shares.
 */
function namesWalk(): DocumentWalk
{
    $path = specFixturePath('schema-names/main.yaml');
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile($path);

    return new DocumentWalk($path, $document);
}

/**
 * The body schema position of one operation of that fixture.
 */
function bodyPositionOf(DocumentWalk $walk, string $path): SchemaPosition
{
    $operation = $walk->child($walk->child($walk->child($walk->root(), 'paths'), $path), 'post');

    return $walk->child(
        $walk->child($walk->child($walk->child($operation, 'requestBody'), 'content'), 'application/json'),
        'schema'
    );
}

it('spells a position in the root document as a bare pointer', function (): void {
    $walk = namesWalk();

    expect($walk->pointer(bodyPositionOf($walk, '/local')))
        ->toBe('#/components/schemas/LocalPet');
});

it('spells a position in another file with the path in front of it', function (): void {
    $walk = namesWalk();

    expect($walk->pointer(bodyPositionOf($walk, '/external')))
        ->toBe('other.yaml#/components/schemas/Pet');
});

it('resolves a relative reference against the file that wrote it', function (): void {
    $walk = namesWalk();
    $wrapper = bodyPositionOf($walk, '/from-a-subdirectory');
    $pet = $walk->child($walk->child($wrapper, 'properties'), 'pet');

    // `../other.yaml` is written in sub/wrapper.yaml, so it climbs out of the
    // subdirectory rather than out of the root document's own directory.
    expect($walk->pointer($pet))->toBe('other.yaml#/components/schemas/Pet');
});

it('collapses an alias onto the position its target is written at', function (): void {
    $walk = namesWalk();

    expect($walk->pointer(bodyPositionOf($walk, '/alias')))
        ->toBe('other.yaml#/components/schemas/Pet');
});

it('points at a whole file with an empty pointer', function (): void {
    $walk = namesWalk();

    expect($walk->pointer(bodyPositionOf($walk, '/whole-file')))->toBe('Address.yaml#');
});

it('leaves an inline schema at the position it is written at', function (): void {
    $walk = namesWalk();

    expect($walk->pointer(bodyPositionOf($walk, '/inline')))
        ->toBe('#/paths/~1inline/post/requestBody/content/application~1json/schema');
});

it('escapes a segment holding the characters a pointer reserves', function (): void {
    $walk = namesWalk();
    $paths = $walk->child($walk->root(), 'paths');

    expect($walk->pointer($walk->child($paths, '/whole-file')))->toBe('#/paths/~1whole-file');
});

it('reads back the raw node written at a position', function (): void {
    $walk = namesWalk();
    $raw = $walk->rawAt(bodyPositionOf($walk, '/external'));

    expect($raw)->toBe(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]);
});

it('says nothing rather than guessing for a file it cannot read', function (): void {
    $walk = namesWalk();

    expect($walk->rawAt(new SchemaPosition('/nowhere/at/all.yaml', '')))->toBeNull();
});

it('says nothing rather than guessing for a pointer the file has nothing at', function (): void {
    $walk = namesWalk();

    expect($walk->rawAt(bodyPositionOf($walk, '/local')))->not->toBeNull()
        ->and($walk->rawAt(new SchemaPosition(specFixturePath('schema-names/main.yaml'), '/nope')))
        ->toBeNull();
});

it('names a schema from where it is written', function (string $path, ?string $expected): void {
    $walk = namesWalk();

    expect($walk->name(bodyPositionOf($walk, $path)))->toBe($expected);
})->with([
    'a local component' => ['/local', 'LocalPet'],
    'a component in another file' => ['/external', 'Pet'],
    'a whole file' => ['/whole-file', 'Address'],
    'a file name that is no PHP identifier' => ['/unnameable-file', null],
    'an inline schema' => ['/inline', null],
]);

it('gives the root document no name of its own', function (): void {
    $walk = namesWalk();

    expect($walk->name($walk->root()))->toBeNull();
});

it('names nothing for a position that is neither a component nor a file', function (): void {
    $walk = namesWalk();
    $pet = bodyPositionOf($walk, '/local');

    expect($walk->name($walk->child($walk->child($pet, 'properties'), 'name')))->toBeNull();
});

// The guards refuse pure reference cycles before the parser, but they only see
// the root document and say so. A chain closing across files is out of their
// reach, and an unterminated loop here would be a hang rather than a message.
it('stops rather than looping on an alias chain that closes on itself', function (): void {
    $directory = sys_get_temp_dir().'/lsf-walk-'.bin2hex(random_bytes(6));
    mkdir($directory);

    file_put_contents($directory.'/main.yaml', Yaml::dump([
        'components' => ['schemas' => ['A' => ['$ref' => './other.yaml#/components/schemas/B']]],
    ], 10));
    file_put_contents($directory.'/other.yaml', Yaml::dump([
        'components' => ['schemas' => ['B' => ['$ref' => './main.yaml#/components/schemas/A']]],
    ], 10));

    try {
        /** @var array<string, mixed> $document */
        $document = Yaml::parseFile($directory.'/main.yaml');
        $walk = new DocumentWalk($directory.'/main.yaml', $document);

        $schemas = $walk->child($walk->child($walk->root(), 'components'), 'schemas');

        expect($walk->pointer($walk->child($schemas, 'A')))
            ->toBeIn(['#/components/schemas/A', 'other.yaml#/components/schemas/B']);
    } finally {
        unlink($directory.'/main.yaml');
        unlink($directory.'/other.yaml');
        rmdir($directory);
    }
});
