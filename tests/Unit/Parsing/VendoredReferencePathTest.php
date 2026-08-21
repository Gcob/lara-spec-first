<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\VendoredReferencePath;

it('mirrors the host as a directory and the path beneath it', function (): void {
    expect(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/api/common.yaml'))
        ->toBe('/vendor/schemas.example.com/api/common.yaml');
});

it('fetching the same URL twice lands in the same place', function (): void {
    $first = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml');
    $second = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml');

    expect($first)->toBe($second);
});

// The caller (`RemoteReferenceGuard`) already splits the `#fragment` off before
// asking where a URL vendors to, but this stays cheap insurance: a fragment
// that reached here anyway must not carve out a second file for one document.
it('ignores a fragment if one reaches it anyway', function (): void {
    expect(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml#/components/schemas/User'))
        ->toBe(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml'));
});

it('names something for a bare host with no path', function (): void {
    expect(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com'))
        ->toBe('/vendor/schemas.example.com/index');
});

it('folds a query string into the filename rather than colliding two URLs onto one file', function (): void {
    $withoutQuery = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml');
    $queryA = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml?version=1');
    $queryB = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml?version=2');

    expect($queryA)->not->toBe($withoutQuery)
        ->and($queryA)->not->toBe($queryB)
        ->and($queryA)->toEndWith('.yaml')
        ->and(dirname($queryA))->toBe(dirname($withoutQuery));
});

it('is stable for the same query string', function (): void {
    $first = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml?version=1');
    $second = VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/common.yaml?version=1');

    expect($first)->toBe($second);
});

it('folds a port into the host directory instead of dropping it', function (): void {
    expect(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com:8443/common.yaml'))
        ->toBe('/vendor/schemas.example.com_8443/common.yaml');
});

// An allowed host names a document to fetch, not a location on this
// filesystem to write it — refusing `.`/`..` outright closes the door on
// `https://allowed.host/../../../outside.yaml` writing outside `$vendorRoot`
// regardless of how deep the traversal goes.
it('refuses a path that tries to climb out of the vendor root', function (string $url): void {
    expect(fn () => VendoredReferencePath::forUrl('/vendor', $url))
        ->toThrow(RemoteReferenceException::class, 'will not vendor');
})->with([
    'https://schemas.example.com/../outside.yaml',
    'https://schemas.example.com/../../../outside.yaml',
    'https://schemas.example.com/a/../../outside.yaml',
    'https://schemas.example.com/./common.yaml',
]);

it('does not mistake a name merely containing dots for a traversal segment', function (): void {
    expect(VendoredReferencePath::forUrl('/vendor', 'https://schemas.example.com/v1.2/common.yaml'))
        ->toBe('/vendor/schemas.example.com/v1.2/common.yaml');
});
