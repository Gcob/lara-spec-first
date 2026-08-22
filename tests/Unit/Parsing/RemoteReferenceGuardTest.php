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
it('removes a faulted reference rather than leaving a network scheme string behind', function (): void {
    $result = (new RemoteReferenceGuard)->resolve(['$ref' => 'https://example.com/s.yaml'], specDir());

    expect($result->document)->toBe([])
        ->and($result->document)->not->toHaveKey('$ref');
});

// The `$ref` key and only it: at 3.0 a Reference Object's siblings are just
// `summary`/`description`, but the guard cannot tell which version it is
// walking, and dropping the node wholesale is a decision made once here for
// every version — see the 3.1 cases below for what that would have cost.
it('removes the $ref key and keeps what was written beside it', function (): void {
    $result = (new RemoteReferenceGuard)->resolve([
        '$ref' => 'https://example.com/s.yaml',
        'summary' => 'A summary',
        'description' => 'A description',
    ], specDir());

    expect($result->document)->toBe([
        'summary' => 'A summary',
        'description' => 'A description',
    ]);
});

// 3.1: a Schema Object is JSON Schema 2020-12, so `$ref` sits *beside*
// applicable keywords rather than excluding them. Blanking the node would
// delete `properties` and `required` the author wrote, silently — this is the
// case that decided the behaviour above.
it('keeps the local schema keywords a 3.1 Schema Object writes beside its $ref', function (): void {
    $result = (new RemoteReferenceGuard)->resolve([
        'schema' => [
            '$ref' => 'https://example.com/s.yaml#/User',
            'description' => 'A user',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string']],
        ],
    ], specDir());

    expect($result->document)->toBe([
        'schema' => [
            'description' => 'A user',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string']],
        ],
    ]);
});

// Same at a 3.1 Path Item, where `$ref` coexists with `parameters`.
it('keeps the parameters a 3.1 Path Item writes beside its $ref', function (): void {
    $result = (new RemoteReferenceGuard)->resolve([
        'paths' => ['/users' => [
            '$ref' => 'https://example.com/paths.yaml#/users',
            'parameters' => [['name' => 'page', 'in' => 'query']],
        ]],
    ], specDir());

    expect($result->document)->toBe([
        'paths' => ['/users' => [
            'parameters' => [['name' => 'page', 'in' => 'query']],
        ]],
    ]);
});

// What is given up, stated rather than left to be inferred: the reference
// itself, and therefore whatever it pointed at. Nothing local is lost, but the
// document the parser sees describes less than the file does — which is what
// `$neutralized` exists to tell a caller.
it('says the document was rewritten when a reference was removed from it', function (): void {
    $refused = (new RemoteReferenceGuard)->resolve(['$ref' => 'https://example.com/s.yaml'], specDir());
    $untouched = (new RemoteReferenceGuard)->resolve(['$ref' => '#/components/schemas/User'], specDir());

    expect($refused->neutralized)->toBeTrue()
        ->and($refused->isClean())->toBeFalse()
        ->and($untouched->neutralized)->toBeFalse()
        ->and($untouched->isClean())->toBeTrue();
});

// `$seen` remembers a refusal, not only a success: one bad URL named from
// several positions is one problem, and reporting it once per position turns a
// report meant to be read into the same line repeated.
it('reports a refused URL once however many positions name it', function (): void {
    $result = (new RemoteReferenceGuard)->resolve([
        'a' => ['$ref' => 'https://evil.example.com/s.yaml#/A'],
        'b' => ['$ref' => 'https://evil.example.com/s.yaml#/B'],
        'c' => ['nested' => ['$ref' => 'https://evil.example.com/s.yaml']],
    ], specDir());

    // Every position is still neutralized — reported once, refused everywhere.
    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class)
        ->and($result->document)->toBe(['a' => [], 'b' => [], 'c' => ['nested' => []]]);
});

it('reports a missing vendored copy once however many positions name it', function (): void {
    $guard = guardWith(['schemas.example.com']);

    $result = $guard->resolve([
        'a' => ['$ref' => 'https://schemas.example.com/common.yaml#/A'],
        'b' => ['$ref' => 'https://schemas.example.com/common.yaml#/B'],
    ], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(MissingVendoredReferenceException::class);
});

// The same memory under `--update-refs`, where repeating the refusal would also
// repeat the request: a host that already answered 500 once is not asked again
// per position.
it('attempts a failing fetch once however many positions name it', function (): void {
    $factory = new Factory;
    $factory->preventStrayRequests();
    $factory->fake([
        'https://schemas.example.com/common.yaml' => Factory::response('server error', 500),
    ]);

    $guard = new RemoteReferenceGuard(
        ['schemas.example.com'],
        vendorRoot(),
        new RemoteReferenceFetcher($factory),
    );

    $result = $guard->resolve([
        'a' => ['$ref' => 'https://schemas.example.com/common.yaml'],
        'b' => ['$ref' => 'https://schemas.example.com/common.yaml'],
        'c' => ['$ref' => 'https://schemas.example.com/common.yaml'],
    ], specDir(), updateRefs: true);

    $factory->assertSentCount(1);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceFetchException::class);
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

// The transitive case, and the property the whole persist gate exists for: a
// vendored document that could not be fully resolved is left on disk exactly as
// upstream wrote it. Writing the neutralized copy back would erase the evidence
// of the fault from the file the next read starts from — and a read that fails
// must not modify a committed file.
it('leaves a vendored document untouched when the walk over it collected a fault', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]],
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class);

    $committed = Yaml::parseFile(vendorRoot().'/schemas.example.com/outer.yaml');

    expect($committed)->toBe(['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]]);
});

// The other half of that gate, and the reason the first half is safe: the file
// on disk still names a URL, so nothing the parser sees may lead into it. The
// parser resolves a *local* `$ref` by opening the file itself, which would hand
// it the very reference this walk refused, one hop later.
it('neutralizes the reference into a vendored document it could not fully resolve', function (): void {
    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]],
    ]);

    $result = $guard->resolve([
        'schema' => ['$ref' => 'https://schemas.example.com/outer.yaml#/User'],
    ], specDir(), updateRefs: true);

    expect($result->document)->toBe(['schema' => []])
        ->and($result->neutralized)->toBeTrue();
});

// The read is idempotent: running it again on unchanged inputs reports the same
// fault, because the first run changed none of its inputs. This is the
// regression the persist gate closes — a second plain build finding nothing left
// to complain about, exiting SUCCESS, and generating a contract quietly missing
// the schema that reference pointed at.
it('reports the same fault again on the next read, having changed nothing', function (): void {
    $fetching = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.yaml' => ['allOf' => [['$ref' => 'https://evil.example.com/inner.yaml']]],
    ]);

    $fetching->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir(), updateRefs: true);

    $before = (string) file_get_contents(vendorRoot().'/schemas.example.com/outer.yaml');

    // Second run: no flag, and no fake named — any HTTP request fails the test.
    $offline = guardWith(['schemas.example.com']);
    $result = $offline->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(RemoteReferenceException::class)
        ->and($result->document)->toBe([])
        ->and((string) file_get_contents(vendorRoot().'/schemas.example.com/outer.yaml'))->toBe($before);
});

// The realistic shape of the same defect, and the one a fresh clone actually
// hits: the parent vendored copy is committed, the transitive one it names never
// was. Nothing is refetched, the missing copy is reported, and the parent is not
// rewritten into a document that no longer names it.
it('leaves a committed parent untouched when the copy it names was never vendored', function (): void {
    $vendoredDir = vendorRoot().'/schemas.example.com';
    mkdir($vendoredDir, 0o777, true);
    $parent = ['allOf' => [['$ref' => 'https://schemas.example.com/inner.yaml']]];
    file_put_contents($vendoredDir.'/outer.yaml', Yaml::dump($parent));

    $guard = guardWith(['schemas.example.com']);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/outer.yaml'], specDir());

    expect($result->faults)->toHaveCount(1)
        ->and($result->faults[0])->toBeInstanceOf(MissingVendoredReferenceException::class)
        ->and(Yaml::parseFile($vendoredDir.'/outer.yaml'))->toBe($parent);
});

// The JSON side of the same gate. `persist()` re-encodes a `.json` copy with
// `json_encode()`, and a PHP `[]` — what a node emptied of its only key becomes
// — encodes as an empty JSON *array* where a Schema Object belongs, which the
// next read would refuse from a place having nothing to do with the original
// reference. The gate is what keeps that shape off disk: a faulted document is
// not re-encoded at all.
it('leaves a vendored JSON document byte-for-byte when the walk over it faulted', function (): void {
    $body = json_encode(['allOf' => [['$ref' => 'https://evil.example.com/inner.json']]]);

    $guard = guardWith(['schemas.example.com'], [
        'https://schemas.example.com/outer.json' => $body,
    ]);

    $result = $guard->resolve(['$ref' => 'https://schemas.example.com/outer.json'], specDir(), updateRefs: true);

    expect($result->faults)->toHaveCount(1)
        ->and((string) file_get_contents(vendorRoot().'/schemas.example.com/outer.json'))->toBe($body);
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
