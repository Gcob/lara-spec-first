<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The fourth equivalence class of the conformance suite: how much of a document
// is there before "no operations" is the right outcome rather than a fault.
// 3.0 requires `paths`; 3.1 does not, so a document naming only `webhooks` or
// only `components` is a legitimate zero-route contract, and a document naming
// none of the three is not a document at all. Each cell below is a different
// point on that line. See docs/project/roadmap.md and
// docs/guide/openapi-support.md#reading-a-document.

/**
 * @return list<Operation>
 */
function extractShapeFixture(string $name): array
{
    return (new OperationExtractor)->extract((new SpecDocumentReader)->read(specFixturePath($name)));
}

// Not even a version to dispatch on — the earliest a document can fail.
it('rejects a document with nothing in it', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('empty-mapping.json')))
        ->toThrow(UnsupportedVersionException::class);
});

// Decodes, but not into something a version strategy could ever examine.
it('rejects a document that does not decode to a mapping', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('root-list.yaml')))
        ->toThrow(UnreadableDocumentException::class);
});

// A version is known, but the root carries none of what that version requires.
it('rejects a 3.0 document with no paths, which 3.0 requires', function (): void {
    expect(fn () => extractShapeFixture('openapi-3.0-no-paths.yaml'))
        ->toThrow(InvalidDocumentException::class);
});

// From here on the shape is valid, and the outcome is a route count, not a
// thrown exception — zero is a legitimate answer once the version allows it.
it('produces zero routes from a 3.1 document naming only webhooks', function (): void {
    expect(extractShapeFixture('openapi-3.1-webhooks-only.yaml'))->toBe([]);
});

it('produces zero routes from a 3.1 document naming only components', function (): void {
    expect(extractShapeFixture('openapi-3.1-components-only.yaml'))->toBe([]);
});
