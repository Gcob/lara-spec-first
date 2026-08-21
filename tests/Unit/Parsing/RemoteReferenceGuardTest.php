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
        // This is also what proves neutralization never lets `cebe\openapi\`
        // — or anything else — reach a disallowed reference over the network:
        // every "refuses" test in this file uses this guard.
        $factory->fake(function (): never {
            throw new RuntimeException('Unexpected HTTP request in a unit test.');
        });
    }

    return new RemoteReferenceGuard($allowedHosts, vendorRoot(), new RemoteReferenceFetcher($factory));
}

it('refuses a reference that would be fetched over the network', function (): void {
    $result = (new SpecDocumentReader)->read(specFixturePath('remote-reference.yaml'));

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class)
        ->and($result->faults[0]->getMessage())->toContain('will not fetch');
});

it('names the reference and what to do instead', function (): void {
    $result = (new SpecDocumentReader)->read(specFixturePath('remote-reference.yaml'));

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0]->getMessage())->toContain('Vendor the document into the repository');
});

// The document is still readable — a disallowed remote reference is a fault
// collected alongside the rest, not a reason to give up on the whole document.
// See the neutralization tests below for what actually happens to the node.
it('still returns a document alongside the fault', function (): void {
    $result = (new SpecDocumentReader)->read(specFixturePath('remote-reference.yaml'));

    expect($result->document)->not->toBeNull();
});

// Matched on the presence of a scheme rather than on a list of protocols: the
// parser hands the string to a stream wrapper, and PHP has more of those than a
// denylist would keep up with.
it('refuses any scheme, not a list of them', function (string $reference): void {
    $result = (new RemoteReferenceGuard)->resolve(['$ref' => $reference], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class);
})->with([
    'https://example.com/s.yaml',
    'http://example.com/s.yaml',
    'ftp://example.com/s.yaml',
    'file:///etc/passwd',
    'phar://payload.phar/s.yaml',
]);

it('leaves local references alone', function (string $reference): void {
    $result = (new RemoteReferenceGuard)->resolve(['$ref' => $reference], specDir());

    expect($result->faults)->toBe([])
        ->and($result->document)->toBe(['$ref' => $reference]);
})->with([
    '#/components/schemas/User',
    'common.yaml',
    'common.yaml#/components/schemas/User',
    '../shared/common.yaml',
]);

// Every `$ref` is examined, including those in positions the cycle detector
// treats as data. The question here is not what the reference means but whether
// the parser would dial out for it — and for an `example` it would, since it
// turns any array carrying a `$ref` into a Reference.
it('looks everywhere, including where a reference would be data', function (array $document): void {
    expect((new RemoteReferenceGuard)->resolve($document, specDir())->faults)->toHaveCount(1);
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
    $result = (new RemoteReferenceGuard)->resolve(['$ref' => '#/components/schemas/User'], specDir());

    expect($result->faults)->toBe([]);
});

// --- Neutralization: a faulted reference never reaches the parser as itself ---

// The property the whole class exists to guarantee once it stopped throwing:
// collecting a fault instead of aborting means the walk keeps going, and if the
// disallowed `$ref` were left as a network scheme string, `cebe\openapi\` would
// try to resolve it itself the next time anything opened the document — the
// exact hole the allowlist exists to close, reopened by the collector meant to
// report it.
it('replaces a faulted reference rather than leaving a network scheme string behind', function (): void {
    $result = (new RemoteReferenceGuard)->resolve(['$ref' => 'https://example.com/s.yaml'], specDir());

    expect($result->document)->toBe([])
        ->and($result->document)->not->toHaveKey('$ref');
});

// The Reference Object as a whole is neutralized, siblings included: 3.1 allows
// `summary` and `description` beside `$ref`, and neither can hold meaning once
// the reference itself is refused.
it('drops the whole Reference Object, siblings included, not only the $ref key', function (): void {
    $result = (new RemoteReferenceGuard)->resolve([
        '$ref' => 'https://example.com/s.yaml',
        'summary' => 'A summary',
        'description' => 'A description',
    ], specDir());

    expect($result->document)->toBe([]);
});

// This is the security regression the neutralization tests above exist for,
// made explicit: even with the offending reference nested three levels deep,
// nothing in this call ever reaches the network. `guardWith()` with no fakes
// named fails the test the instant any HTTP request is attempted.
it('touches the network for no reference at all when one is refused', function (): void {
    $guard = guardWith(['schemas.example.com']);

    $result = $guard->resolve([
        'paths' => ['/users' => ['get' => ['responses' => ['200' => [
            'content' => ['application/json' => ['schema' => [
                '$ref' => 'https://evil.example.com/schemas.yaml',
            ]]],
        ]]]]],
    ], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class);
});

// --- Vendoring: an allowed host is fetched, committed, and resolved locally ---

it('refuses to vendor a reference whose host is not allowed, without touching the network', function (): void {
    $guard = guardWith(['schemas.example.com']);

    $result = $guard->resolve(['$ref' => 'https://evil.example.com/s.yaml'], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class)
        ->and($result->faults[0]->getMessage())->toContain('will not fetch');
});

it('names the reference, the expected path, and the flag to run when nothing is vendored yet', function (): void {
    $guard = guardWith(['schemas.example.com'], fakes: null);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(MissingVendoredReferenceException::class);

    $message = $result->faults[0]->getMessage();

    expect($message)->toContain('https://schemas.example.com/common.yaml')
        ->and($message)->toContain('schemas.example.com/common.yaml') // the expected vendored path
        ->and($message)->toContain('php artisan spec:build --update-refs');
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

    $result = $guard->resolve(
        ['$ref' => 'https://schemas.example.com/common.yaml#/components/schemas/User'],
        specDir(),
        updateRefs: true,
    );

    $expected = RelativeFilePath::from(
        specDir(),
        vendorRoot().'/schemas.example.com/common.yaml',
    ).'#/components/schemas/User';

    expect($result->document['$ref'])->toBe($expected);
});

it('reads an already-vendored copy without any network access', function (): void {
    $vendoredDir = vendorRoot().'/schemas.example.com';
    mkdir($vendoredDir, 0o777, true);
    file_put_contents($vendoredDir.'/common.yaml', Yaml::dump(['components' => ['schemas' => ['User' => ['type' => 'object']]]]));

    // No fakes named: guardWith() makes any HTTP call fail the test.
    $guard = guardWith(['schemas.example.com']);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir());

    expect($result->document['$ref'])->toContain('schemas.example.com/common.yaml');
});

it('collects a fault instead of throwing when the fetch itself fails', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => Factory::response('server error', 500),
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceFetchException::class)
        ->and(is_file(vendorRoot().'/schemas.example.com/common.yaml'))->toBeFalse();
});

it('collects a fault and vendors nothing when the fetched body does not decode', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/common.yaml' => 'not: [valid: yaml',
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/common.yaml'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceFetchException::class)
        ->and(is_file(vendorRoot().'/schemas.example.com/common.yaml'))->toBeFalse();
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

// A document fetched as JSON must be committed as JSON: the diff a reviewer
// reads should be upstream's content with only its own `$ref` rewritten, not
// the whole file reformatted into a different notation because it happened to
// name a further reference.
it('re-persists a vendored JSON document as JSON rather than YAML', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.json' => json_encode([
            'allOf' => [['$ref' => 'https://schemas.example.com/inner.json']],
        ]),
        'https://schemas.example.com/inner.json' => json_encode(['type' => 'object']),
    ]);

    $guard->resolve(['$ref' => 'https://schemas.example.com/outer.json'], specDir(), updateRefs: true);

    $committedRaw = (string) file_get_contents(vendorRoot().'/schemas.example.com/outer.json');

    expect(ltrim($committedRaw))->toStartWith('{');

    $committed = json_decode($committedRaw, true);
    expect($committed['allOf'][0]['$ref'])->not->toContain('https://')
        ->and($committed['allOf'][0]['$ref'])->toContain('inner.json');
});

// The transitive case for neutralization: a vendored document naming a
// disallowed reference of its own is rewritten so the committed copy carries
// no network scheme string either — the fault is collected, but the file
// `--update-refs` just fetched and committed is not left with a live hole in
// it for the next read to trip over.
it('neutralizes a disallowed reference inside a document it just vendored, in the committed copy too', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]],
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class);

    $committed = Yaml::parseFile(vendorRoot().'/schemas.example.com/outer.yaml');

    expect($committed)->toBe(['allOf' => [[]]]);
});

// `$chain` alone only guards against a cycle along one branch — it says
// nothing about a URL named from a dozen unrelated positions, or reached twice
// through two different vendored documents. Without a memory of what this
// `resolve()` call already vendored, that document would be fetched and
// written once per occurrence rather than once.
it('fetches a URL named from more than one position only once', function (): void {
    $factory = new Factory;
    $factory->preventStrayRequests();
    $factory->fake([
        'https://schemas.example.com/common.yaml' => Yaml::dump(['type' => 'object']),
    ]);

    $guard = new RemoteReferenceGuard(
        ['schemas.example.com'],
        vendorRoot(),
        new RemoteReferenceFetcher($factory),
    );

    $guard->resolve([
        'a' => ['$ref' => 'https://schemas.example.com/common.yaml'],
        'b' => ['$ref' => 'https://schemas.example.com/common.yaml'],
        'c' => ['nested' => ['$ref' => 'https://schemas.example.com/common.yaml']],
    ], specDir(), updateRefs: true);

    $factory->assertSentCount(1);
});

it('collects a fault instead of looping on a remote reference cycle', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/a.yaml' => ['$ref' => 'https://schemas.example.com/b.yaml'],
        'https://schemas.example.com/b.yaml' => ['$ref' => 'https://schemas.example.com/a.yaml'],
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/a.yaml'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(CircularRemoteReferenceException::class);
});
