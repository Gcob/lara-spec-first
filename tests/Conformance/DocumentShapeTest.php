<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

// The fourth equivalence class of the conformance suite: how much of a document
// is there before "no operations" is the right outcome rather than a fault.
// 3.0 requires `paths`; 3.1 does not, so a document naming only `webhooks` or
// only `components` is a legitimate zero-route contract, and a document naming
// none of the three is not a document at all. Each cell below is a different
// point on that line. See docs/project/roadmap.md and
// docs/guide/openapi-support.md#reading-a-document.

// The earliest a document can fail: `DocumentDecoder::decode()` rejects a
// root-level list before a version is even looked for, let alone dispatched on.
it('rejects a document that does not decode to a mapping', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('root-list.yaml')))
        ->toThrow(UnreadableDocumentException::class);
});

// One step later: the document decodes to a mapping, but there is nothing in
// it to detect a version from — not even a version this package rejects.
it('rejects a document with nothing in it', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('empty-mapping.json')))
        ->toThrow(UnsupportedVersionException::class);
});

// A version is known, but the root carries none of what that version requires.
it('rejects a 3.0 document with no paths, which 3.0 requires', function (): void {
    expect(fn () => extractFixture('openapi-3.0-no-paths.yaml'))
        ->toThrow(InvalidDocumentException::class);
});

// The same failure, on the version that makes it interesting: `paths` alone
// is not what 3.1 requires, so a document naming none of the three root keys
// it accepts is still rejected, distinctly from every case above it — a
// different exception than the empty document, and a different one from the
// 3.0 case, because a version was in fact detected and dispatched to.
it('rejects a 3.1 document declaring none of the three root keys it accepts', function (): void {
    expect(fn () => extractFixture('openapi-3.1-no-root-keys.yaml'))
        ->toThrow(InvalidDocumentException::class, 'declares none of them');
});

// From here on the shape is valid, and the outcome is a route count, not a
// thrown exception — zero is a legitimate answer once the version allows it.
it('produces zero routes from a 3.1 document naming only webhooks', function (): void {
    expect(extractFixture('openapi-3.1-webhooks-only.yaml'))->toBe([]);
});

it('produces zero routes from a 3.1 document naming only components', function (): void {
    expect(extractFixture('openapi-3.1-components-only.yaml'))->toBe([]);
});
