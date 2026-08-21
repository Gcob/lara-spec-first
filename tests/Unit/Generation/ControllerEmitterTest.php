<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Generation\ControllerEmitter;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Generation\PlannedController;

/*
 * The metadata a generated controller carries is output, so it is asserted the
 * way any other output is. Three things are promised in writing — provenance,
 * findings, navigation — and two more that are easy to lose without noticing: the
 * JSON pointer's escaping, and the absence of anything that would make two runs
 * differ.
 *
 * Emitter-level rather than through the command: what is under test is the text,
 * and reaching it through a build would mean a filesystem, a document and a
 * planner standing between the assertion and the string it is about.
 *
 * @see docs/guide/code-generation.md — "Every generated file explains itself"
 */

/**
 * The PHP of one operation's controller.
 *
 * The spec path is passed as the build passes it — already relative to the
 * project root — because that is the only form a generated file may carry.
 */
function emittedController(
    Operation $operation,
    string $specPath = 'openapi.yaml',
    bool $customControllerExists = false,
): string {
    $emitter = new ControllerEmitter('App\\Http\\Generated', $specPath);
    $planned = new PlannedController(
        $operation,
        ControllerName::for($operation),
        $customControllerExists,
    );

    return $emitter->emit($planned)->contents;
}

/**
 * One operation, with everything the docblock reads named rather than positional.
 *
 * @param  list<SecurityRequirement>|null  $security
 */
function operationFor(
    string $method = 'get',
    string $path = '/users/{id}',
    ?string $operationId = 'showUser',
    Audience $audience = Audience::Public,
    ?Lifecycle $lifecycle = Lifecycle::Beta,
    bool $deprecated = false,
    ?string $sunset = null,
    ?array $security = null,
    ?string $controller = null,
): Operation {
    // Named rather than positional: `Operation` takes ten parameters, six of them
    // with defaults, so a reordering there would bind the wrong values here and
    // nothing would say so.
    return new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: $operationId,
        tags: [],
        audience: $audience,
        lifecycle: $lifecycle,
        deprecated: $deprecated,
        sunset: $sunset,
        security: $security,
        controller: $controller,
    );
}

/**
 * The docblock alone, so an assertion about it cannot be satisfied by the code
 * below it.
 */
function emittedDocblock(string $contents): string
{
    $start = strpos($contents, '/**');
    $end = strpos($contents, '*/');

    if ($start === false || $end === false) {
        throw new RuntimeException('the emitted file carries no docblock');
    }

    return substr($contents, $start, $end - $start + 2);
}

describe('the docblock every generated controller carries', function (): void {
    it('carries the marker, the provenance, the findings and the navigation', function (): void {
        $docblock = emittedDocblock(emittedController(operationFor()));

        expect($docblock)
            ->toContain(GeneratedFile::MARKER)
            ->toContain('DO NOT EDIT')
            ->toContain('Provenance')
            ->toContain('Findings')
            ->toContain('Navigation');
    });

    // Unconditional, and this is the case where a condition could hide: the
    // barest operation the contract can express — no `operationId`, no lifecycle
    // claim, no deprecation, no security. Emitting the norm only where there is
    // something to say would make its absence unreadable.
    it('carries all three parts for the barest operation a contract can express', function (): void {
        $docblock = emittedDocblock(emittedController(operationFor(
            path: '/users',
            operationId: null,
            lifecycle: null,
        )));

        expect($docblock)
            ->toContain('Provenance')
            ->toContain('Findings')
            ->toContain('Navigation');
    });

    it('names the specification as the build named it, never absolutely', function (): void {
        $contents = emittedController(operationFor(), 'spec/openapi.yaml');

        expect($contents)->toContain('spec/openapi.yaml')
            ->and($contents)->not->toContain(dirname(__DIR__, 3));
    });

    // The navigation points at the file that reaches this class, which is the one
    // thing a reader cannot work out from the class itself.
    it('points at the routes file that reaches the class', function (): void {
        expect(emittedController(operationFor()))->toContain('@see routes.php');
    });

    // `phpdoc_separation` ships in Pint's Laravel preset and inserts a blank line
    // before an annotation. Emitting it means a consumer's formatter finds nothing
    // to change; without it the formatter and the build rewrite each other on
    // every run, and the idempotence this package promises would hold only for
    // projects that format nothing.
    it('separates the annotation the way a formatter would', function (): void {
        expect(emittedController(operationFor()))->toContain(" *\n *   @see routes.php");
    });

    // Generated code is read far more than it is written. The width is the
    // repository's own, and it is enforced here because a finding interpolates
    // values taken from the document.
    //
    // The second case is the one wrapping on spaces cannot serve: `x-sunset` is
    // deliberately unparsed, so a URL or a hand-typed value carrying no space at
    // all reaches the docblock as one token. It has to be cut rather than left to
    // run — a 208-character line is what this asserted away.
    it('wraps every line of the docblock rather than running one to any length', function (
        string $sunset,
    ): void {
        $docblock = emittedDocblock(emittedController(operationFor(
            deprecated: true,
            sunset: $sunset,
        )));

        foreach (explode("\n", $docblock) as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(100, 'a docblock line runs long: '.$line);
        }
    })->with([
        'a value with spaces' => [str_repeat('long-sunset-value ', 20)],
        'one token with none' => [str_repeat('a', 200)],
        'a URL' => ['https://example.test/'.str_repeat('sunset-policy/', 20)],
    ]);

    // Cut, not truncated: the value is what a reader came here for, and dropping
    // its tail would make the finding lie by omission.
    it('keeps the whole of a value it had to cut across lines', function (): void {
        $docblock = emittedDocblock(emittedController(operationFor(
            deprecated: true,
            sunset: str_repeat('a', 200),
        )));

        $joined = str_replace(["\n", ' ', '*'], '', $docblock);

        expect($joined)->toContain(str_repeat('a', 200));
    });
});

describe('the source map', function (): void {
    it('carries the JSON pointer of the operation it came from', function (): void {
        expect(emittedController(operationFor(method: 'get', path: '/users/{id}')))
            ->toContain('#/paths/~1users~1{id}/get');
    });

    // A JSON pointer escapes `~` before `/`, or the escape of one eats the other:
    // replacing `/` first would turn `~1` into `~01`, which points nowhere.
    it('escapes a tilde and a slash the way a JSON pointer does', function (): void {
        expect(emittedController(operationFor(path: '/a~b/{id}', operationId: 'showAB')))
            ->toContain('#/paths/~1a~0b~1{id}/get');
    });

    // The method is a document key rather than an HTTP verb here, so it stays as
    // the document writes it: `#/paths/~1users/GET` resolves to nothing.
    it('names the method the way the document keys it', function (): void {
        $contents = emittedController(operationFor(method: 'delete', path: '/users/{id}', operationId: 'removeUser'));

        expect($contents)->toContain('/delete')
            ->and($contents)->not->toContain('/DELETE');
    });
});

describe('the findings', function (): void {
    it('says whether the class name was declared or derived', function (
        ?string $operationId,
        string $expected,
    ): void {
        expect(emittedController(operationFor(operationId: $operationId)))->toContain($expected);
    })->with([
        'declared' => ['showUser', 'Class name taken from the operation\'s `operationId`.'],
        'derived' => [null, 'Class name derived from the method and path'],
    ]);

    // Effective rather than as written: an absent extension is resolved before the
    // operation reaches the emitter, so the docblock says what applies. Saying
    // what was written would make a reader open the document to learn what it
    // means.
    it('states the effective audience and lifecycle', function (): void {
        expect(emittedController(operationFor(audience: Audience::Internal, lifecycle: Lifecycle::Stable)))
            ->toContain('Audience `internal`, lifecycle `stable`');
    });

    it('says an operation makes no lifecycle claim rather than inventing one', function (): void {
        expect(emittedController(operationFor(lifecycle: null)))
            ->toContain('Audience `public`, no lifecycle claim');
    });

    it('says the operation answers 501 and why the return type is mixed', function (): void {
        expect(emittedController(operationFor()))
            ->toContain('answers 501')
            ->toContain('The return type is `mixed`');
    });

    it('reports a deprecation, with the removal date when the contract states one', function (
        ?string $sunset,
        string $expected,
    ): void {
        expect(emittedController(operationFor(deprecated: true, sunset: $sunset)))->toContain($expected);
    })->with([
        'no sunset' => [null, 'Marked `deprecated` with no `x-sunset`'],
        'a sunset' => ['2027-01-01', 'Marked `deprecated`, to be removed on 2027-01-01.'],
    ]);

    // Asserted against the finding's own sentence rather than the word anywhere in
    // the file: the day a boilerplate line mentions deprecation for an unrelated
    // reason, a whole-file assertion fails for a reason that has nothing to do
    // with what this test is about.
    it('says nothing about a deprecation the contract does not declare', function (): void {
        expect(emittedDocblock(emittedController(operationFor())))
            ->not->toContain('Marked `deprecated`');
    });

    // Read and not enforced is the finding that matters most today: a reader who
    // sees `security` in the contract would otherwise assume this endpoint is
    // protected by something the package put there.
    it('reports security requirements it reads and does not enforce', function (): void {
        $contents = emittedController(operationFor(security: [
            SecurityRequirement::fromSchemes(['bearer' => []]),
            SecurityRequirement::fromSchemes(['oauth' => ['read']]),
        ]));

        expect($contents)
            ->toContain('Declares 2 security requirement(s)')
            ->toContain('not protected by anything this package put there');
    });

    // Two cases rather than one dataset, because the two nulls mean opposite
    // things: an operation that says nothing inherits the document's
    // requirements, and one that declares an empty list requires none.
    it('reports nothing for an operation that inherits the document\'s requirements', function (): void {
        expect(emittedController(operationFor(security: null)))->not->toContain('security requirement');
    });

    it('reports nothing for an operation that explicitly requires none', function (): void {
        expect(emittedController(operationFor(security: [])))->not->toContain('security requirement');
    });
});

describe('what the metadata deliberately does not carry', function (): void {
    // A clock in the file means different bytes on every run: every file
    // rewritten every time, and — for a project that tracks its generated tree —
    // a diff in every file on every build.
    it('records nothing about when it was generated', function (): void {
        $contents = emittedController(operationFor());

        expect($contents)->not->toMatch('/\d{4}-\d{2}-\d{2}/')
            ->and($contents)->not->toContain('generated at')
            ->and($contents)->not->toContain('created_at')
            ->and($contents)->not->toContain('updated_at');
    });

    it('emits the same bytes twice for the same operation', function (): void {
        $operation = operationFor();

        expect(emittedController($operation))->toBe(emittedController($operation));
    });

    // A specification is data and a generated file is code, so every value the
    // docblock takes from the document is neutralized on the way in. A `*/` that
    // reaches the file ends the comment early and turns whatever follows into a
    // statement — in a file the application autoloads.
    it('cannot have its comment closed by a value taken from the document', function (): void {
        $contents = emittedController(operationFor(
            deprecated: true,
            sunset: '*/ echo "owned";',
        ));

        expect(substr_count($contents, '*/'))->toBe(1)
            ->and($contents)->toContain('*\/')
            ->and($contents)->not->toContain('*/ echo');
    });
});

describe('the two-class seam, when the contract declares a custom controller', function (): void {
    /*
     * The other half of the table in controllers.md: a name that exists in order
     * to name this class is a name that can be depended on, so the parent drops
     * `final` and the child may extend it.
     */

    it('emits a class nothing forbids extending', function (): void {
        $contents = emittedController(operationFor(controller: 'App\\Http\\Controllers\\UserController'));

        expect($contents)->toContain('class UserController extends SpecController')
            ->and($contents)->not->toContain('final class');
    });

    it('takes its name from the class the contract named', function (): void {
        expect(emittedController(operationFor(
            operationId: 'showUserAccountDetails',
            controller: 'App\\Http\\Controllers\\UserController',
        )))->toContain('class UserController extends SpecController');
    });

    it('says where the name came from, and what it buys', function (): void {
        expect(emittedController(operationFor(controller: 'App\\Http\\Controllers\\UserController')))
            ->toContain('Class name taken from `x-controller`')
            ->toContain('why it is not `final`');
    });

    // Point at what actually runs. A reader landing on a generated default that
    // has been overridden is the most common way to misread a codebase like this.
    it('points at the child when the child exists', function (): void {
        $contents = emittedController(
            operationFor(controller: 'App\\Http\\Controllers\\UserController'),
            customControllerExists: true,
        );

        expect($contents)
            ->toContain('@see \\App\\Http\\Controllers\\UserController')
            ->toContain('what the route actually reaches')
            ->toContain('The route reaches the custom controller rather than this class');
    });

    // And says so plainly when it does not, because the honest answer then is that
    // this class is what answers, with a 501.
    it('says the child is missing rather than pointing at nothing', function (): void {
        $contents = emittedController(operationFor(controller: 'App\\Http\\Controllers\\UserController'));

        expect($contents)
            ->toContain('no file for it exists yet')
            ->toContain('answers 501')
            ->and($contents)->not->toContain('@see \\App\\Http\\Controllers\\UserController');
    });

    // A blank line inside a docblock is ` *`, never ` *   `: trailing whitespace is
    // something a formatter strips, and a formatter with something to strip is one
    // fighting the next build.
    it('leaves no trailing whitespace in the docblock it writes', function (): void {
        $contents = emittedController(operationFor(controller: 'App\\Http\\Controllers\\UserController'));

        foreach (explode("\n", $contents) as $line) {
            expect($line)->toBe(rtrim($line), 'a line carries trailing whitespace: '.$line);
        }
    });

    // Kept to a phrase short enough to survive wrapping: findings are wrapped at
    // the repository's width, so an assertion on a long sentence would fail the
    // day a word ahead of it changes length.
    it('advertises the extension point on a class nothing may extend', function (): void {
        expect(emittedController(operationFor()))->toContain('Declare `x-controller`');
    });
});

describe('the two-class seam', function (): void {
    // `final` for as long as nothing may extend a generated controller: the name
    // of a controller with no `x-controller` is derived and disposable, so an
    // import of it would be a dependency on a name nobody chose.
    it('emits a final class over the shipped base', function (): void {
        expect(emittedController(operationFor()))
            ->toContain('final class ShowUserController extends SpecController')
            ->toContain('use Gcob\\LaraSpecFirst\\Http\\Controllers\\SpecController;');
    });

    // One method, named the same in every generated controller, so the routes
    // file can name it as a plain string and `route:cache` can serialize it.
    it('carries exactly one method, and it is routeAction', function (): void {
        $contents = emittedController(operationFor(path: '/users'));

        expect(substr_count($contents, 'public function '))->toBe(1)
            ->and($contents)->toContain('public function routeAction(): mixed');
    });

    /*
     * The signature is the contract with the child, and PHP is what makes it one:
     * an override may not add a required parameter, so a parameterless parent
     * would forbid a custom controller from ever declaring `{id}`. Found in the
     * Workbench rather than by reasoning — a child declaring `routeAction(string
     * $id)` over a parameterless parent is a fatal error at load.
     */

    it('declares one parameter per path parameter, named as the document names it', function (
        string $path,
        string $expected,
    ): void {
        expect(emittedController(operationFor(path: $path, operationId: 'op')))
            ->toContain('public function routeAction('.$expected.'): mixed');
    })->with([
        'none' => ['/users', ''],
        'one' => ['/users/{id}', 'string $id'],
        'two, in path order' => ['/users/{userId}/posts/{postId}', 'string $userId, string $postId'],
        'a name the document chose' => ['/users/{user_id}', 'string $user_id'],
    ]);

    // The emitter interpolates the name it is handed, and what keeps that safe is
    // the planner refusing a name PHP could not carry before anything is emitted —
    // asserted in tests/Unit/Generation/BuildPlannerTest.php, where the refusal
    // lives. What is asserted here is the other half of that division: nothing
    // reaches this class that it would have to sanitize, so a parameter name
    // arrives in the signature exactly as the document wrote it.
    it('writes the document\'s own spelling, because Laravel matches by name', function (): void {
        expect(emittedController(operationFor(path: '/users/{userId}', operationId: 'op')))
            ->toContain('string $userId')
            ->and(emittedController(operationFor(path: '/users/{userId}', operationId: 'op')))
            ->not->toContain('string $user_id');
    });

    it('throws the exception Laravel renders as 501, naming the operation', function (): void {
        expect(emittedController(operationFor(method: 'get', path: '/users/{id}')))
            ->toContain("OperationNotImplementedException::operation('get /users/{id}', 'showUser')");
    });

    // The second argument is what the 501 body turns into `php artisan spec:make
    // <name>`, so an operation with no `operationId` has to pass `null` rather
    // than an empty string — a printed invocation with nothing after it would be
    // worse than the method and path the exception falls back to.
    it('passes null rather than an empty name for an operation with no operationId', function (): void {
        expect(emittedController(operationFor(operationId: null, path: '/users')))
            ->toContain("OperationNotImplementedException::operation('get /users', null)");
    });

    // The import has no leading separator, which is what `use` requires: a
    // `use \Foo;` does not parse.
    it('imports without a leading separator', function (): void {
        expect(emittedController(operationFor()))->not->toContain('use \\');
    });
});
