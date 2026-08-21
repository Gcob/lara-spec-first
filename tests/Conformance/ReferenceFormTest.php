<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Exceptions\CyclicReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The second equivalence class of the conformance suite: `$ref` takes several
// forms across a document, and each one is a different code path through the
// reading engine — a local lookup, a file read, a network guard, or the cycle
// check that has to run before any of them. See docs/project/roadmap.md.

/**
 * @return list<Operation>
 */
function extractReferenceFormFixture(string $name): array
{
    return (new OperationExtractor)->extract((new SpecDocumentReader)->read(specFixturePath($name)));
}

it('resolves a local $ref that reaches a schema directly', function (): void {
    expect(extractReferenceFormFixture('local-schema-ref.yaml'))->toHaveCount(1);
});

// Also the fixture behind "Path Item reference forms: to another file", below —
// the same resolution the parser performs either way, only the position of the
// `$ref` differs.
it('resolves a $ref that reaches into another file', function (): void {
    expect(extractReferenceFormFixture('path-item-ref-file/spec.yaml'))->toHaveCount(1);
});

it('refuses a $ref that would reach over the network', function (): void {
    expect(fn () => extractReferenceFormFixture('remote-reference.yaml'))
        ->toThrow(RemoteReferenceException::class);
});

it('refuses a pure reference cycle before the parser ever sees it', function (): void {
    expect(fn () => extractReferenceFormFixture('cycle-pointer.yaml'))
        ->toThrow(CyclicReferenceException::class);
});

// The form a cycle is not: an ordinary self-referential schema, reached through
// content rather than through another reference. Stated beside the cycle above
// so the distinction the whole guard exists to make is visible in one place.
it('accepts a recursive schema, which is not a reference cycle', function (): void {
    expect(extractReferenceFormFixture('recursive-schema.yaml'))->toHaveCount(1);
});

// A Path Item's `$ref` is special-cased by the parser and takes three forms,
// only two of which it can actually resolve. All three are asserted together so
// the boundary between them stays a single, deliberate line rather than three
// independent facts that could drift apart.
describe('Path Item reference forms', function (): void {
    it('resolves a Path Item that refers to another path', function (): void {
        expect(extractReferenceFormFixture('path-item-ref-path.yaml'))->toHaveCount(2);
    });

    it('resolves a Path Item that lives in another file', function (): void {
        expect(extractReferenceFormFixture('path-item-ref-file/spec.yaml'))->toHaveCount(1);
    });

    // The one form the parser drops in silence rather than resolving: refused
    // by name before extraction reaches it. See KnownParserBugsTest.php for
    // this defect's permanent case.
    it('refuses a Path Item that refers into components.pathItems', function (): void {
        expect(fn () => extractReferenceFormFixture('path-item-ref-component.yaml'))
            ->toThrow(RejectedConstructException::class);
    });
});
