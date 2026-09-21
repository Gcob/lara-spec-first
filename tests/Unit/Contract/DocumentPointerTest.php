<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\DocumentPointer;

// The spelling of a position, and the walk that reads one back. Both directions
// live in one class precisely so they cannot disagree, so both are pinned here
// rather than only the one a given caller happens to use.
//
// @see docs/guide/code-generation/generated-file-anatomy.md — "The source map"

it('escapes the two characters RFC 6901 reserves', function (): void {
    expect(DocumentPointer::escape('/users/{id}'))->toBe('~1users~1{id}')
        ->and(DocumentPointer::escape('a~1b'))->toBe('a~01b');
});

it('reads an escaped segment back to what it was', function (string $segment): void {
    expect(DocumentPointer::unescape(DocumentPointer::escape($segment)))->toBe($segment);
})->with(['/users/{id}', 'a~1b', 'plain', '~', '/']);

it('puts the file in front of a position written outside the root document', function (): void {
    expect(DocumentPointer::inFile('other.yaml', '/components/schemas/Pet'))
        ->toBe('other.yaml#/components/schemas/Pet')
        ->and(DocumentPointer::inFile('../shared/Pet.yaml', ''))
        ->toBe('../shared/Pet.yaml#');
});

it('finds the node a pointer names', function (): void {
    $document = ['paths' => ['/users/{id}' => ['get' => ['operationId' => 'showUser']]]];

    expect(DocumentPointer::nodeAt('#/paths/~1users~1{id}/get', $document))
        ->toBe(['operationId' => 'showUser']);
});

// A `$ref` fragment arrives without the `#` this package writes in front of its
// own pointers, and both have to reach the same node.
it('accepts a pointer written with or without the leading hash', function (): void {
    $document = ['components' => ['schemas' => ['Pet' => ['type' => 'object']]]];

    expect(DocumentPointer::nodeAt('/components/schemas/Pet', $document))
        ->toBe(DocumentPointer::nodeAt('#/components/schemas/Pet', $document));
});

it('answers with the whole document for the empty pointer', function (): void {
    $document = ['openapi' => '3.1.0'];

    expect(DocumentPointer::nodeAt('', $document))->toBe($document);
});

it('says nothing for a position the document has nothing at', function (mixed $pointer): void {
    $document = ['components' => ['schemas' => ['Pet' => 'not an object']]];

    expect(DocumentPointer::nodeAt($pointer, $document))->toBeNull();
})->with([
    'a key that is not there' => ['#/components/schemas/Dog'],
    'a value that is not an object' => ['#/components/schemas/Pet'],
    'a path running through a scalar' => ['#/components/schemas/Pet/type'],
]);
