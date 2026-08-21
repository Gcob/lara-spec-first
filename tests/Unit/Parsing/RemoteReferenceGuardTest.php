<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\Exceptions\CircularRemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\MissingVendoredReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceFetchException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\RelativeFilePath;
use Gcob\LaraSpecFirst\Parsing\RemoteReferences\RemoteReferenceFetcher;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Illuminate\Http\Client\Factory;
use Symfony\Component\Yaml\Yaml;

/**
 * A fresh scratch directory per test run, following the same shape
 * `GeneratedTreeTest` uses: a static so Pest's closure-per-test binding does
 * not lose it, cleaned up before and after the whole file.
 */
function vendorRoot(): string
{
    static $root = null;

    return $root ??= sys_get_temp_dir().'/lsf-vendor-'.bin2hex(random_bytes(6));
}

/**
 * The directory the "referencing document" is pretended to live in — distinct
 * from the vendor root, since a rewritten `$ref` has to be relative to it.
 */
function specDir(): string
{
    static $dir = null;

    return $dir ??= sys_get_temp_dir().'/lsf-spec-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(vendorRoot()));
    exec('rm -rf '.escapeshellarg(specDir()));
    mkdir(vendorRoot(), 0o777, true);
    mkdir(specDir(), 0o777, true);
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(vendorRoot()));
    exec('rm -rf '.escapeshellarg(specDir()));
});

/**
 * A guard wired to a faked HTTP factory, so no test in this file ever reaches
 * a real network — `RemoteReferenceFetcher` wraps the same `Factory` instance
 * `Http::fake()` would, just without needing a booted application.
 *
 * @param  list<string>  $allowedHosts
 * @param  ?array<string, mixed>  $fakes  url pattern => response body (a string
 *                                        is returned as-is; an array is dumped
 *                                        as YAML first)
 */
function guardWith(array $allowedHosts, ?array $fakes = null): RemoteReferenceGuard
{
    $factory = new Factory;
    $factory->preventStrayRequests();

    if ($fakes !== null) {
        $factory->fake(array_map(
            static fn (mixed $body): mixed => is_array($body) ? Yaml::dump($body) : $body,
            $fakes,
        ));
    } else {
        // Any test that reaches this without stubbing a URL wants to prove no
        // request was made — failing loudly beats a real socket in a test.
        $factory->fake(function (): never {
            throw new RuntimeException('Unexpected HTTP request in a unit test.');
        });
    }

    return new RemoteReferenceGuard($allowedHosts, vendorRoot(), new RemoteReferenceFetcher($factory));
}

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
    expect(fn () => (new RemoteReferenceGuard)->resolve(['$ref' => $reference], specDir()))
        ->toThrow(RemoteReferenceException::class);
})->with([
    'https://example.com/s.yaml',
    'http://example.com/s.yaml',
    'ftp://example.com/s.yaml',
    'file:///etc/passwd',
    'phar://payload.phar/s.yaml',
]);

it('leaves local references alone', function (string $reference): void {
    (new RemoteReferenceGuard)->resolve(['$ref' => $reference], specDir());
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
    expect(fn () => (new RemoteReferenceGuard)->resolve($document, specDir()))
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

it('is silent when no host is allowed, which is the default', function (): void {
    (new RemoteReferenceGuard)->resolve(['$ref' => '#/components/schemas/User'], specDir());
})->throwsNoExceptions();

// --- Vendoring: an allowed host is fetched, committed, and resolved locally ---

it('refuses to vendor a reference whose host is not allowed, without touching the network', function (): void {
    $guard = guardWith(['schemas.example.com']);

    expect(fn () => $guard->resolve(['$ref' => 'https://evil.example.com/s.yaml'], specDir()))
        ->toThrow(RemoteReferenceException::class, 'will not fetch');
});

it('names the reference, the expected path, and the flag to run when nothing is vendored yet', function (): void {
    $guard = guardWith(['schemas.example.com'], fakes: null);
    $caught = null;

    try {
        $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir());
    } catch (MissingVendoredReferenceException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught?->getMessage())
        ->toContain('https://schemas.example.com/common.yaml')
        ->toContain('schemas.example.com/common.yaml') // the expected vendored path
        ->toContain('php artisan spec:build --update-refs');
});

it('fetches and commits the reference on --update-refs', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => ['components' => ['schemas' => ['User' => ['type' => 'object']]]],
    ]);

    $guard->resolve(
        ['$ref' => 'https://schemas.example.com/common.yaml#/components/schemas/User'],
        specDir(),
        updateRefs: true,
    );

    $vendoredFile = vendorRoot().'/schemas.example.com/common.yaml';

    expect(is_file($vendoredFile))->toBeTrue()
        ->and(Yaml::parseFile($vendoredFile))->toBe(['components' => ['schemas' => ['User' => ['type' => 'object']]]]);
});

it('rewrites the $ref to a path relative to the referencing document', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => ['components' => ['schemas' => ['User' => ['type' => 'object']]]],
    ]);

    $resolved = $guard->resolve(
        ['$ref' => 'https://schemas.example.com/common.yaml#/components/schemas/User'],
        specDir(),
        updateRefs: true,
    );

    $expected = RelativeFilePath::from(
        specDir(),
        vendorRoot().'/schemas.example.com/common.yaml',
    ).'#/components/schemas/User';

    expect($resolved['$ref'])->toBe($expected);
});

it('reads an already-vendored copy without any network access', function (): void {
    $vendoredDir = vendorRoot().'/schemas.example.com';
    mkdir($vendoredDir, 0o777, true);
    file_put_contents($vendoredDir.'/common.yaml', Yaml::dump(['components' => ['schemas' => ['User' => ['type' => 'object']]]]));

    // No fakes named: guardWith() makes any HTTP call fail the test.
    $guard = guardWith(['schemas.example.com']);

    $resolved = $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir());

    expect($resolved['$ref'])->toContain('schemas.example.com/common.yaml');
});

it('throws when the fetch itself fails', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => Factory::response('server error', 500),
    ]);

    expect(fn () => $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir(), updateRefs: true))
        ->toThrow(RemoteReferenceFetchException::class);

    expect(is_file(vendorRoot().'/schemas.example.com/common.yaml'))->toBeFalse();
});

it('throws and vendors nothing when the fetched body does not decode', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => 'not: [valid: yaml',
    ]);

    expect(fn () => $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir(), updateRefs: true))
        ->toThrow(RemoteReferenceFetchException::class);

    expect(is_file(vendorRoot().'/schemas.example.com/common.yaml'))->toBeFalse();
});

// --- Transitive: a vendored document naming its own remote reference ---

it('vendors a reference found inside a document it just vendored', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://schemas.example.com/inner.yaml']]],
        'https://schemas.example.com/inner.yaml' => ['type' => 'object'],
    ]);

    $guard->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir(), updateRefs: true);

    $outerFile = vendorRoot().'/schemas.example.com/outer.yaml';
    $innerFile = vendorRoot().'/schemas.example.com/inner.yaml';

    expect(is_file($outerFile))->toBeTrue()
        ->and(is_file($innerFile))->toBeTrue();

    // The committed outer copy no longer names a URL — it was rewritten so
    // that a rebuild of this file alone stays offline too.
    $committed = Yaml::parseFile($outerFile);
    expect($committed['allOf'][0]['$ref'])->not->toContain('https://')
        ->and($committed['allOf'][0]['$ref'])->toContain('inner.yaml');
});

it('refuses a transitively-vendored reference whose host is not allowed', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]],
    ]);

    expect(fn () => $guard->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir(), updateRefs: true))
        ->toThrow(RemoteReferenceException::class, 'will not fetch');
});

it('raises a clear error instead of looping on a remote reference cycle', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/a.yaml' => ['$ref' => 'https://schemas.example.com/b.yaml'],
        'https://schemas.example.com/b.yaml' => ['$ref' => 'https://schemas.example.com/a.yaml'],
    ]);

    expect(fn () => $guard->resolve(['$ref' => 'https://schemas.example.com/a.yaml'], specDir(), updateRefs: true))
        ->toThrow(CircularRemoteReferenceException::class);
});
