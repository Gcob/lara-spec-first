<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Exceptions\NotImplementedYetException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

it('refuses a reference that would be fetched over the network', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('remote-reference.yaml')))
        ->toThrow(RemoteReferenceException::class, 'will not fetch');
});

it('names the reference and what to do instead', function (): void {
    expect(fn () => (new SpecDocumentReader)->read(specFixturePath('remote-reference.yaml')))
        ->toThrow(RemoteReferenceException::class, 'Vendor the document into the repository');
});

// Matched on the presence of a scheme rather than on a list of protocols: the
// parser hands the string to a stream wrapper, and PHP has more of those than a
// denylist would keep up with.
it('refuses any scheme, not a list of them', function (string $reference): void {
    expect(fn () => (new RemoteReferenceGuard)->assertNoRemoteReferences(['$ref' => $reference]))
        ->toThrow(RemoteReferenceException::class);
})->with([
    'https://example.com/s.yaml',
    'http://example.com/s.yaml',
    'ftp://example.com/s.yaml',
    'file:///etc/passwd',
    'phar://payload.phar/s.yaml',
]);

it('leaves local references alone', function (string $reference): void {
    (new RemoteReferenceGuard)->assertNoRemoteReferences(['$ref' => $reference]);
})->with([
    '#/components/schemas/User',
    'common.yaml',
    'common.yaml#/components/schemas/User',
    '../shared/common.yaml',
])->throwsNoExceptions();

// Every `$ref` is examined, including those in positions the cycle detector
// treats as data. The question here is not what the reference means but whether
// the parser would dial out for it — and for an `example` it would, since it
// turns any array carrying a `$ref` into a Reference.
it('looks everywhere, including where a reference would be data', function (array $document): void {
    expect(fn () => (new RemoteReferenceGuard)->assertNoRemoteReferences($document))
        ->toThrow(RemoteReferenceException::class);
})->with([
    'nested deep' => [['components' => ['schemas' => ['A' => ['properties' => [
        'b' => ['$ref' => 'https://example.com/s.yaml'],
    ]]]]]],
    'inside an example' => [['components' => ['schemas' => ['A' => [
        'example' => ['$ref' => 'https://example.com/s.yaml'],
    ]]]]],
    'at the root' => [['$ref' => 'https://example.com/s.yaml']],
]);

// A setting that is read and quietly does nothing is worse than one that is
// missing: whoever set it has every reason to believe it took effect. Until the
// fetching behind it exists, allowing a host says so out loud.
it('refuses to pretend an allowed host took effect', function (): void {
    expect(fn () => (new RemoteReferenceGuard(['schemas.example.com']))->assertNoRemoteReferences([]))
        ->toThrow(NotImplementedYetException::class, 'is not implemented yet');
});

it('names the setting, what it will do, and where to follow it', function (): void {
    expect(fn () => (new RemoteReferenceGuard(['schemas.example.com']))->assertNoRemoteReferences([]))
        ->toThrow(NotImplementedYetException::class, 'lara-spec-first.remote_references.allowed_hosts');
});

it('is silent when no host is allowed, which is the default', function (): void {
    (new RemoteReferenceGuard)->assertNoRemoteReferences(['$ref' => '#/components/schemas/User']);
})->throwsNoExceptions();
