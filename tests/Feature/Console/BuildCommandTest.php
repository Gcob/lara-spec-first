<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The generated tree goes to a temporary directory, and its namespace is
 * registered with an autoloader for the duration of the test.
 *
 * That autoloader is doing what Composer does in a real application: PSR-4 maps
 * `App\Http\Generated\` to `app/Http/Generated/` there, and nothing here can rely
 * on that because the tree is not in this package's autoload map. Three lines of
 * `spl_autoload_register` buy the same thing without writing generated PHP into
 * the repository, which would then need a `.gitignore` entry of its own and would
 * survive an interrupted run.
 */

/**
 * One temporary tree for this file, computed once.
 *
 * A function with a static rather than a property on the test case: Pest closures
 * are bound to the test instance at runtime but typed as a pending call, so state
 * hung on `$this` is invisible to static analysis. A named function says the same
 * thing and can be checked.
 */
function buildTree(): string
{
    static $tree = null;

    return $tree ??= sys_get_temp_dir().'/lsf-build-'.bin2hex(random_bytes(6));
}

function buildNamespace(): string
{
    static $namespace = null;

    return $namespace ??= 'LsfBuild'.bin2hex(random_bytes(4));
}

beforeAll(function (): void {
    $prefix = buildNamespace().'\\';
    $tree = buildTree();

    spl_autoload_register(static function (string $class) use ($prefix, $tree): void {
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $file = $tree.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }
    });
});

beforeEach(function (): void {
    // Each test starts from an empty tree, so what one of them wrote can never
    // be what another one asserts.
    exec('rm -rf '.escapeshellarg(buildTree()));

    config()->set(GeneratedRoutesLocator::SETTING, buildTree());
    config()->set('lara-spec-first.generated.namespace', buildNamespace());
    config()->set('lara-spec-first.spec.path', specFixturePath('operations.yaml'));
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(buildTree()));
});

function build(): int
{
    return app(Kernel::class)->call('spec:build');
}

/**
 * @return list<string> every path under the generated tree, relative to it
 */
function treeContents(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $found = [];

    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($entries as $entry) {
        $found[] = substr($entry->getPathname(), strlen($root) + 1);
    }

    sort($found);

    return $found;
}

it('emits one controller per operation and the routes file', function (): void {
    expect(build())->toBe(0)
        ->and(treeContents(buildTree()))->toBe([
            'Controllers/CreatePostController.php',
            'Controllers/DeleteUsersIdController.php',
            'Controllers/ShowCurrentUserController.php',
            'Controllers/ShowUserController.php',
            GeneratedRoutesLocator::FILE,
        ]);
});

// The fixture declares `delete /users/{id}` without an `operationId`, which is
// what makes it worth building against: the derived name and the declared one
// have to come out of the same run.
it('names a controller from the operationId, or from the method and path', function (): void {
    build();

    expect(is_file(buildTree().'/Controllers/ShowUserController.php'))->toBeTrue()
        ->and(is_file(buildTree().'/Controllers/DeleteUsersIdController.php'))->toBeTrue();
});

it('emits PHP that parses', function (): void {
    build();

    foreach (treeContents(buildTree()) as $relative) {
        exec('php -l '.escapeshellarg(buildTree().'/'.$relative), $output, $status);

        expect($status)->toBe(0, $relative.' does not parse: '.implode("\n", $output));
    }
});

// Idempotence, as the validation asks for it: running it twice in a row changes
// nothing the second time.
it('changes nothing on a second run against an unchanged specification', function (): void {
    build();

    $before = [];

    foreach (treeContents(buildTree()) as $relative) {
        $before[$relative] = [
            file_get_contents(buildTree().'/'.$relative),
            filemtime(buildTree().'/'.$relative),
        ];
    }

    build();

    $after = [];

    foreach (treeContents(buildTree()) as $relative) {
        $after[$relative] = [
            file_get_contents(buildTree().'/'.$relative),
            filemtime(buildTree().'/'.$relative),
        ];
    }

    expect($after)->toBe($before);
});

// The flag is part of the command's signature, which is public API surface the
// moment the package ships — and it is the only way to point the build at a
// document other than the configured one.
it('reads the specification a flag names instead of the configured one', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('does-not-exist.yaml'));

    $exit = app(Kernel::class)->call('spec:build', ['--spec' => specFixturePath('operations.yaml')]);

    expect($exit)->toBe(0)
        ->and(treeContents(buildTree()))->toContain('Controllers/ShowUserController.php');
});

// `changedNothing()` is what makes idempotence legible from the outside, so the
// command says it in words rather than leaving a reader to notice that every
// count came back zero.
it('says it changed nothing when a second run changes nothing', function (): void {
    build();

    $output = new BufferedOutput;
    app(Kernel::class)->call('spec:build', [], $output);

    expect($output->fetch())->toContain('Already up to date.');
});

// The invariant, checked where a consumer would feel it: the build owns its own
// directory and nothing else, so a file beside the tree is untouched.
it('writes nothing outside the tree it owns', function (): void {
    $neighbour = dirname(buildTree()).'/lsf-neighbour-'.bin2hex(random_bytes(4));
    mkdir($neighbour, 0o777, true);
    file_put_contents($neighbour.'/untouched.php', 'kept');

    try {
        build();

        expect(scandir($neighbour))->toBe(['.', '..', 'untouched.php'])
            ->and(file_get_contents($neighbour.'/untouched.php'))->toBe('kept');
    } finally {
        exec('rm -rf '.escapeshellarg($neighbour));
    }
});

// The property that keeps idempotence true in a real project rather than only in
// a test. Almost every Laravel application formats its code, and `phpdoc_separation`
// ships in both Pint's Laravel preset and php-cs-fixer's defaults — so output that
// is not already canonical under it would be rewritten by the consumer's formatter
// and rewritten back by the next build, forever. Emitting the canonical form is
// what stops the two from fighting.
it('emits output a formatter finds nothing to change in', function (): void {
    build();

    exec(
        'vendor/bin/pint --test '.escapeshellarg(buildTree()).' 2>&1',
        $output,
        $status,
    );

    expect($status)->toBe(0, "Pint would rewrite generated output:\n".implode("\n", $output));
});

it('carries provenance and findings into every file it writes', function (): void {
    build();

    $controller = file_get_contents(buildTree().'/Controllers/ShowUserController.php');

    expect($controller)->toContain(GeneratedFile::MARKER)
        ->and($controller)->toContain('#/paths/~1users~1{id}/get')
        ->and($controller)->toContain('Findings')
        ->and($controller)->toContain('Navigation');
});

// Named from the project root, and asserted as the absence of the alternative.
// A generated file may be committed — that is the consumer's `.gitignore` choice,
// not ours — and an absolute path in a repository differs between every developer
// and every CI runner, which is a diff nobody made and one machine's directory
// layout published.
it('names its source from the project root rather than absolutely', function (): void {
    build();

    $controller = file_get_contents(buildTree().'/Controllers/ShowUserController.php');

    expect($controller)->toContain('tests/Fixtures/operations.yaml')
        ->and($controller)->not->toContain(dirname(__DIR__, 3));
});

// The whole point of the build, end to end: a contract becomes a route that
// answers. 501 rather than 404, because the specification says the endpoint is
// there and nothing should contradict it at the wire.
// The parameterized case matters on its own: Laravel hands route parameters to
// the action by reflection, and a generated `routeAction()` that declares none
// has to survive being handed one.
it('produces routes that answer 501 until something implements them', function (
    string $method,
    string $uri,
): void {
    build();

    require buildTree().'/'.GeneratedRoutesLocator::FILE;

    $response = app(HttpKernel::class)->handle(Request::create($uri, $method));

    expect($response->getStatusCode())->toBe(501);
})->with([
    'literal path' => ['GET', '/users/me'],
    'templated path' => ['GET', '/users/42'],
    'a verb that is not GET' => ['POST', '/posts'],
    'a second verb on one path' => ['DELETE', '/users/42'],
]);

it('registers the routes in the order the document writes them', function (): void {
    build();

    require buildTree().'/'.GeneratedRoutesLocator::FILE;

    $uris = array_values(array_map(
        static fn (Route $route): string => $route->uri(),
        array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_contains($route->getActionName(), 'Controller@routeAction'),
        ),
    ));

    expect($uris)->toBe(['users/me', 'users/{id}', 'users/{id}', 'posts']);
});

it('fails without writing anything when there is no specification', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('does-not-exist.yaml'));

    expect(build())->toBe(1)
        ->and(treeContents(buildTree()))->toBe([]);
});
