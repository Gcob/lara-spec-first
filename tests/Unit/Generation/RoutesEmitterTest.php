<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Generation\PlannedController;
use Gcob\LaraSpecFirst\Generation\RoutesEmitter;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;

/*
 * The routes file is the only generated file the runtime opens, which changes
 * what its metadata is for: a reader who lands here is usually holding an error
 * the whole application raised at boot. So the same three parts are asserted as
 * for a controller, plus the one thing this file has and a controller does not —
 * references to other generated code, and what to do when PHP cannot find one.
 *
 * @see docs/guide/code-generation/index.md — "The routes are one file, and the only one the runtime opens"
 */

/**
 * The PHP of the routes file for a list of `[method, path, operationId]` rows.
 *
 * @param  list<array{0: string, 1: string, 2?: string|null}>  $rows  in document order
 */
function emittedRoutes(array $rows, string $specPath = 'openapi.yaml'): string
{
    $planned = [];

    foreach ($rows as $index => $row) {
        // Named rather than positional, because `Operation` takes ten parameters
        // and a reordering there would bind the wrong values here silently.
        $operation = new Operation(
            index: $index,
            method: HttpMethod::from($row[0]),
            path: PathTemplate::fromString($row[1]),
            operationId: $row[2] ?? null,
        );

        $planned[] = new PlannedController($operation, ControllerName::for($operation));
    }

    return (new RoutesEmitter('App\\Http\\Generated', $specPath))->emit($planned)->contents;
}

describe('the metadata the routes file carries', function (): void {
    it('carries the marker, the provenance, the findings and the navigation', function (): void {
        expect(emittedRoutes([['get', '/users', 'listUsers']]))
            ->toContain(GeneratedFile::MARKER)
            ->toContain('DO NOT EDIT')
            ->toContain('Provenance')
            ->toContain('Findings')
            ->toContain('Navigation');
    });

    // `#/paths` rather than one operation's pointer: this file is the whole
    // collection, and naming any single operation would be a pointer that is
    // right about two lines of the file and wrong about the rest.
    it('names the specification and the position the whole file came from', function (): void {
        $contents = emittedRoutes([['get', '/users', 'listUsers']], 'spec/openapi.yaml');

        expect($contents)
            ->toContain('spec/openapi.yaml')
            ->toContain('#/paths')
            ->and($contents)->not->toContain(dirname(__DIR__, 3));
    });

    // The count is the finding a reader can check against their own document,
    // which is what makes an operation the build silently dropped visible.
    it('reports how many operations it registered', function (): void {
        expect(emittedRoutes([
            ['get', '/users', 'listUsers'],
            ['post', '/users', 'createUser'],
        ]))->toContain('2 operation(s), registered in the order the document writes them');
    });

    it('points at the directory holding one class per operation', function (): void {
        expect(emittedRoutes([['get', '/users', 'listUsers']]))->toContain('@see Controllers/');
    });

    // A block comment rather than a docblock, and the distinction is not cosmetic:
    // `/**` immediately above a `use` statement documents that import, so the
    // file's own provenance would end up attached to whichever class sorts first.
    it('carries the file comment as a block rather than as a docblock', function (): void {
        $contents = emittedRoutes([['get', '/users', 'listUsers']]);

        expect($contents)->toContain("/*\n * ".GeneratedFile::MARKER)
            ->and($contents)->not->toContain('/**');
    });

    it('records nothing about when it was generated', function (): void {
        expect(emittedRoutes([['get', '/users', 'listUsers']]))->not->toMatch('/\d{4}-\d{2}-\d{2}/');
    });

    it('emits the same bytes twice for the same operations', function (): void {
        $rows = [['get', '/users', 'listUsers'], ['get', '/users/{id}', 'showUser']];

        expect(emittedRoutes($rows))->toBe(emittedRoutes($rows));
    });

    // The same width the controller docblock is held to. Nothing interpolates a
    // document value into these lines today except the specification's path, which
    // is exactly why the guard belongs here rather than nowhere.
    it('keeps every comment line within the width the repository writes', function (): void {
        $contents = emittedRoutes([['get', '/users', 'listUsers']], 'spec/openapi.yaml');

        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with(ltrim($line), '*') || str_starts_with($line, '//')) {
                expect(mb_strlen($line))->toBeLessThanOrEqual(100, 'a comment line runs long: '.$line);
            }
        }
    });

    it('writes to the path the runtime looks for', function (): void {
        $operation = new Operation(
            index: 0,
            method: HttpMethod::Get,
            path: PathTemplate::fromString('/users'),
            operationId: 'listUsers',
        );
        $planned = new PlannedController($operation, ControllerName::for($operation));

        expect((new RoutesEmitter('App\\Http\\Generated', 'openapi.yaml'))->emit([$planned])->relativePath)
            ->toBe(GeneratedRoutesLocator::FILE);
    });
});

describe('the note above the generated imports', function (): void {
    // A class-not-found on generated code is the most likely error anyone meets
    // with this package, and the least informative one PHP knows how to raise —
    // here in the file the application loads at boot, so it takes the whole
    // application down rather than one endpoint. The first answer is a command
    // rather than an explanation, because running the build is the fix in the
    // normal case: a fresh clone that does not commit its generated tree.
    it('says what to do when PHP cannot find one of them', function (): void {
        expect(emittedRoutes([['get', '/users', 'listUsers']]))
            ->toContain('php artisan spec:build')
            ->toContain('the specification no longer describes')
            ->toContain('git history');
    });

    // The note sits above one sorted block, so it covers a line it is not about:
    // a class-not-found on `Route` is a broken installation rather than a build
    // the specification can repair. Naming the exception is cheaper than a second
    // import block, which an import-ordering formatter could reorder across.
    it('names the framework import as the exception', function (): void {
        expect(emittedRoutes([['get', '/users', 'listUsers']]))
            ->toContain('the framework import beside them is')
            ->toContain('If PHP cannot find a controller');
    });

    it('sits immediately above the imports it is about', function (): void {
        $contents = emittedRoutes([['get', '/users', 'listUsers']]);

        expect($contents)->toMatch('/\/\/ Every controller imported below is generated;[\s\S]*?\nuse /');
    });

    // Grouped above the block rather than repeated over each line: twenty
    // operations should not mean twenty copies of one paragraph.
    it('appears once however many controllers are imported', function (): void {
        $contents = emittedRoutes([
            ['get', '/users', 'listUsers'],
            ['post', '/users', 'createUser'],
            ['get', '/users/{id}', 'showUser'],
        ]);

        expect(substr_count($contents, 'Every controller imported below is generated'))->toBe(1)
            ->and(substr_count($contents, 'use App\\Http\\Generated\\Controllers\\'))->toBe(3);
    });

    // Two names the note deliberately does not carry. `x-controller` is not read
    // yet, and every route points at a generated class; `spec:watch` does not
    // exist, and printing a command nobody can run would be worse than saying
    // nothing.
    // DECISION: this assertion is a debt tripwire, and it is meant to go red the
    // day `x-controller` or `spec:watch` ships. When it does, add the line to the
    // note and update this test — do not relax the assertion, which is the only
    // thing standing between a shipped feature and a note that never mentions it.
    it('names no command and no extension that does not exist yet', function (): void {
        $contents = emittedRoutes([['get', '/users', 'listUsers']]);

        expect($contents)->not->toContain('x-controller')
            ->and($contents)->not->toContain('spec:watch');
    });
});

describe('a contract with nothing to route', function (): void {
    /*
     * A supported outcome rather than an error: a 3.1 document may carry only
     * `webhooks` or only `components`. The file is still written, because it is
     * what replaces the routes a previous build registered — and what it carries
     * has to survive the same formatter every other generated file does.
     */

    it('writes the file, and says why it registers nothing', function (): void {
        expect(emittedRoutes([]))
            ->toContain(GeneratedFile::MARKER)
            ->toContain('No operation, so this file registers none')
            ->toContain('use Illuminate\\Support\\Facades\\Route;');
    });

    it('does not claim a generated import it has none of', function (): void {
        expect(emittedRoutes([]))->not->toContain('Every controller imported below');
    });

    // What `single_line_after_imports` and `single_blank_line_at_eof` would
    // otherwise rewrite, putting the build and a consumer's formatter in a loop
    // over a file neither of them is wrong about.
    it('leaves no empty registration block behind the imports', function (): void {
        $contents = emittedRoutes([]);

        expect($contents)->toEndWith("Route;\n")
            ->and($contents)->not->toContain("\n\n\n");
    });

    it('reports zero operations rather than counting them', function (): void {
        expect(emittedRoutes([]))->not->toContain('0 operation(s)');
    });
});

describe('the registrations', function (): void {
    it('registers every action as a pair of plain strings', function (): void {
        expect(emittedRoutes([['get', '/users/{id}', 'showUser']]))
            ->toContain("Route::get('/users/{id}', [ShowUserController::class, 'routeAction']);");
    });

    it('keeps the document order the specification wrote', function (): void {
        $contents = emittedRoutes([
            ['get', '/users/me', 'showCurrentUser'],
            ['get', '/users/{id}', 'showUser'],
            ['post', '/users', 'createUser'],
        ]);

        preg_match_all("/Route::\\w+\\((?:\\['\\w+'\\], )?'([^']+)'/", $contents, $matches);

        expect($matches[1])->toBe(['/users/me', '/users/{id}', '/users']);
    });

    // `Route::head()` does not exist, because Laravel derives HEAD from GET on its
    // own. A contract declaring `head` explicitly still has to be routed.
    it('routes a verb Laravel has no method for through match', function (): void {
        expect(emittedRoutes([['head', '/health', 'health']]))
            ->toContain("Route::match(['head'], '/health'");
    });

    // A path may legally carry an apostrophe, and a hand-quoted literal would
    // then produce a file that does not parse — in the one generated file the
    // service provider loads at boot.
    it('emits a path that would break a hand-quoted literal', function (): void {
        expect(emittedRoutes([['get', "/it's/{id}", 'showQuoted']]))
            ->toContain("'/it\\'s/{id}'");
    });
});
