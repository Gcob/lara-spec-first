<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Generation\ControllerEmitter;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Generation\PlannedController;
use Gcob\LaraSpecFirst\Generation\RequestName;
use Gcob\LaraSpecFirst\Generation\StatusResponse;
use Symfony\Component\Process\Process;

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
 * @see docs/guide/code-generation/generated-file-anatomy.md — "Every generated file explains itself"
 */

/**
 * The PHP of one operation's controller.
 *
 * The spec path is passed as the build passes it — already relative to the project root — because that is the only form
 * a generated file may carry.
 *
 * @param  list<StatusResponse>  $responses  what each declared status answers with, as the build plans it
 */
function emittedController(
    Operation $operation,
    string $specPath = 'openapi.yaml',
    bool $customControllerExists = false,
    array $responses = [],
): string {
    $emitter = new ControllerEmitter('App\\Http\\Generated', $specPath);
    $planned = new PlannedController(
        $operation,
        ControllerName::for($operation),
        $responses,
        $customControllerExists,
    );

    return $emitter->emit($planned)->contents;
}

/**
 * One operation, with everything the docblock reads named rather than positional.
 *
 * @param  list<SecurityRequirement>|null  $security
 * @param  list<Response>  $responses  what the contract declares, for the questions about a media type
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
    array $responses = [],
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
        responses: $responses,
    );
}

/**
 * One response the contract declares, with the media types it writes a schema for.
 *
 * @param  list<string>  $mediaTypes  empty for a status that declares no body
 */
function declaredResponse(string $status, array $mediaTypes = ['application/json']): Response
{
    $content = [];

    foreach ($mediaTypes as $mediaType) {
        $content[$mediaType] = new Schema(types: [SchemaType::Object]);
    }

    return new Response($status, $content);
}

/**
 * The controller of an operation whose statuses are described by rows, each one both what the contract declares and
 * what the response plan made of it, so the two cannot disagree by accident.
 *
 * A row with a `dto` is a status with a DTO; one without is a status with none, and its `media` says what the contract
 * declares for it (nothing, by default: a body-less status).
 *
 * @param  array<array{status: string, dto?: string, depth?: int, nullable?: bool, itemsNullable?: bool, media?: list<string>}>  $rows
 */
function controllerAnswering(array $rows): string
{
    $declared = [];
    $planned = [];

    foreach ($rows as $row) {
        $dto = $row['dto'] ?? null;
        $declared[] = declaredResponse($row['status'], $row['media'] ?? ($dto === null ? [] : ['application/json']));
        $planned[] = $dto === null
            ? StatusResponse::without($row['status'], 'it has no DTO')
            : StatusResponse::of($row['status'], $dto, $row['depth'] ?? 0, $row['nullable'] ?? false, $row['itemsNullable'] ?? false);
    }

    return emittedController(operationFor(responses: $declared), responses: $planned);
}

/**
 * What `routeAction` declares after its parameters, as written.
 */
function declaredReturnType(string $contents): string
{
    if (preg_match('/public function routeAction\(.*\): (.+)\n/', $contents, $found) !== 1) {
        throw new RuntimeException('the emitted file declares no routeAction');
    }

    return $found[1];
}

/**
 * The `@return` tag on `routeAction`, or null when it carries none.
 */
function declaredReturnTag(string $contents): ?string
{
    return preg_match('/@return (.+)\n     \*\/\n    public function routeAction/', $contents, $found) === 1 ? $found[1] : null;
}

/**
 * The docblock alone, so an assertion about it cannot be satisfied by the code below it.
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
            ->toContain('The return type is `mixed`, because no success response of this operation has a DTO');
    });

    // Each status says what it is, and the return type above them is the sum of the success ones.
    it('names each status response DTO in the controller findings', function (): void {
        $operation = operationFor(responses: [
            declaredResponse('200'),
            declaredResponse('201'),
            declaredResponse('204', []),
            declaredResponse('default', ['text/plain']),
        ]);

        $docblock = emittedDocblock(emittedController($operation, responses: [
            StatusResponse::of('200', 'UserDto', 0),
            StatusResponse::of('201', 'CreateUser201ResponseItemDto', 1),
            StatusResponse::without('204', 'it declares no body'),
            StatusResponse::without('default', 'it declares no JSON media type'),
        ]));

        expect((string) preg_replace('/\s*\n \*\s+/', ' ', $docblock))
            ->toContain('The `200` response is `UserDto`, in `Data/UserDto.php`.')
            ->toContain('The `201` response is a list of `CreateUser201ResponseItemDto`')
            ->toContain('The `204` response has no DTO: it declares no body.')
            ->toContain('The `default` response has no DTO: it declares no JSON media type.')
            ->toContain('The return type is `UserDto|array|Response`');
        expect($docblock)->not->toContain('is read yet');
    });

    it('says an operation that declares no response has no response type', function (): void {
        $docblock = (string) preg_replace('/\s*\n \*\s+/', ' ', emittedDocblock(emittedController(operationFor())));

        expect($docblock)->toContain('The contract declares no response for this operation');
        expect($docblock)->not->toContain('is read yet');
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

describe('the return type routeAction declares', function (): void {
    it('declares the success response DTO as routeAction return type', function (
        array $rows,
        string $type,
        ?string $tag,
    ): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe($type)
            ->and(declaredReturnTag($contents))->toBe($tag);
    })->with([
        'a DTO' => [[['status' => '200', 'dto' => 'UserDto']], 'UserDto', null],
        'a list' => [[['status' => '200', 'dto' => 'UserDto', 'depth' => 1]], 'array', 'list<UserDto>'],
        'a list of lists' => [[['status' => '200', 'dto' => 'UserDto', 'depth' => 2]], 'array', 'list<list<UserDto>>'],
        'the DTO of a status that is also a 4xx' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '400', 'dto' => 'ProblemDto']],
            'UserDto',
            null,
        ],
    ]);

    it('imports each DTO the return type names, from the generated Data namespace', function (): void {
        $contents = controllerAnswering([
            ['status' => '200', 'dto' => 'UserDto'],
            ['status' => '201', 'dto' => 'JobDto', 'depth' => 1],
        ]);

        expect($contents)
            ->toContain("use App\\Http\\Generated\\Data\\JobDto;\nuse App\\Http\\Generated\\Data\\UserDto;");
    });

    it('follows the nullable shapes the response plan carries', function (
        array $rows,
        string $type,
        ?string $tag,
    ): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe($type)
            ->and(declaredReturnTag($contents))->toBe($tag);
    })->with([
        'a nullable body' => [[['status' => '200', 'dto' => 'UserDto', 'nullable' => true]], '?UserDto', null],
        'a nullable list' => [[['status' => '200', 'dto' => 'UserDto', 'depth' => 1, 'nullable' => true]], '?array', 'list<UserDto>|null'],
        'nullable items' => [[['status' => '200', 'dto' => 'UserDto', 'depth' => 1, 'itemsNullable' => true]], 'array', 'list<UserDto|null>'],
        'nullable items in a list of lists' => [
            [['status' => '200', 'dto' => 'UserDto', 'depth' => 2, 'itemsNullable' => true]],
            'array',
            'list<list<UserDto|null>|null>',
        ],
        'a nullable body among several statuses' => [
            [['status' => '200', 'dto' => 'UserDto', 'nullable' => true], ['status' => '202', 'dto' => 'JobDto']],
            'UserDto|JobDto|JsonResponse|null',
            null,
        ],
    ]);

    it('declares a union and JsonResponse when several success statuses exist', function (
        array $rows,
        string $type,
        ?string $tag,
    ): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe($type)
            ->and(declaredReturnTag($contents))->toBe($tag)
            ->and($contents)->toContain('use Illuminate\\Http\\JsonResponse;')
            ->and($contents)->not->toContain('HttpFoundation');
    })->with([
        'two DTOs' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '202', 'dto' => 'JobDto']],
            'UserDto|JobDto|JsonResponse',
            null,
        ],
        'one DTO twice' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '201', 'dto' => 'UserDto']],
            'UserDto|JsonResponse',
            null,
        ],
        'a DTO beside a status that gave way to a fallback' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '201', 'media' => ['application/json']]],
            'UserDto|JsonResponse',
            null,
        ],
        'a DTO beside a list' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '201', 'dto' => 'UserDto', 'depth' => 1]],
            'UserDto|array|JsonResponse',
            'UserDto|list<UserDto>|JsonResponse',
        ],
        'a DTO beside a status with two JSON schemas' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '202', 'media' => ['application/json', 'application/problem+json']]],
            'UserDto|JsonResponse',
            null,
        ],
    ]);

    it('admits a Response where the contract declares an answer that is not JSON', function (array $rows): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe('UserDto|Response')
            ->and($contents)->toContain('use Symfony\\Component\\HttpFoundation\\Response;')
            ->and($contents)->not->toContain('JsonResponse');
    })->with([
        'a 204 with no body' => [[['status' => '200', 'dto' => 'UserDto'], ['status' => '204']]],
        'a 202 with no body' => [[['status' => '200', 'dto' => 'UserDto'], ['status' => '202']]],
        'a 201 offered only as text/csv' => [[['status' => '200', 'dto' => 'UserDto'], ['status' => '201', 'media' => ['text/csv']]]],
        'a 200 offering JSON and text/csv' => [[['status' => '200', 'dto' => 'UserDto', 'media' => ['application/json', 'text/csv']]]],
        'a 302' => [[['status' => '200', 'dto' => 'UserDto'], ['status' => '302']]],
        'a 3XX range' => [[['status' => '200', 'dto' => 'UserDto'], ['status' => '3XX']]],
        'a 204 and several DTO statuses' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '201', 'dto' => 'UserDto'], ['status' => '204']],
        ],
    ]);

    it('keeps mixed when no success response has a DTO', function (array $rows): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe('mixed')
            ->and(declaredReturnTag($contents))->toBeNull()
            ->and($contents)->not->toContain('HttpFoundation')
            ->and($contents)->not->toContain('JsonResponse')
            ->and($contents)->not->toContain('Generated\\Data');
    })->with([
        'a 204' => [[['status' => '204']]],
        'a text/csv-only 200' => [[['status' => '200', 'media' => ['text/csv']]]],
        'two JSON schemas' => [[['status' => '200', 'media' => ['application/json', 'application/problem+json']]]],
        'a schema that gave way to a fallback' => [[['status' => '200', 'media' => ['application/json']]]],
        'a DTO only on an error status' => [[['status' => '200'], ['status' => '400', 'dto' => 'ProblemDto']]],
        'a redirect beside nothing typed' => [[['status' => '200'], ['status' => '302']]],
        'no response at all' => [[]],
    ]);

    it('counts default as success only when no 2xx is declared', function (array $rows, string $type): void {
        $contents = controllerAnswering($rows);

        expect(declaredReturnType($contents))->toBe($type)
            ->and($contents)->not->toContain('use App\\Http\\Generated\\Data\\ProblemDto;');
    })->with([
        'default alone' => [[['status' => 'default', 'dto' => 'UserDto']], 'UserDto'],
        'a 200 beside a default error' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => 'default', 'dto' => 'ProblemDto']],
            'UserDto',
        ],
        'a 2XX range' => [[['status' => '2XX', 'dto' => 'UserDto']], 'UserDto'],
        'a 2XX range beside a default error' => [
            [['status' => '2XX', 'dto' => 'UserDto'], ['status' => 'default', 'dto' => 'ProblemDto']],
            'UserDto',
        ],
        'a 400 never enters it' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '400', 'dto' => 'ProblemDto']],
            'UserDto',
        ],
        'a default beside a body-less 204' => [[['status' => '204'], ['status' => 'default', 'dto' => 'ProblemDto']], 'mixed'],
    ]);

    // The finding is read by a person who opens the file to learn why the type is what it is.
    it('says what routeAction returns and that an error status is thrown', function (
        array $rows,
        array $sentences,
    ): void {
        $docblock = (string) preg_replace('/\s*\n \*\s+/', ' ', emittedDocblock(controllerAnswering($rows)));

        foreach ($sentences as $sentence) {
            expect($docblock)->toContain($sentence);
        }

        expect($docblock)->not->toContain('whatever the response types below say');
    })->with([
        'a DTO' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '400', 'dto' => 'ProblemDto']],
            [
                'The return type is `UserDto`, the DTO of the success response (`200`).',
                'An error status is not returned: throw it (`abort()` or an exception), and the DTO of an error response is for the exception handler.',
            ],
        ],
        'a list' => [
            [['status' => '200', 'dto' => 'UserDto', 'depth' => 1]],
            ['The return type is `array`, the DTO of the success response (`200`).', 'throw it (`abort()` or an exception)'],
        ],
        'several success statuses' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '202', 'dto' => 'JobDto']],
            [
                'The return type is `UserDto|JobDto|JsonResponse`, the DTO of each success response (`200`, `202`).',
                'It admits `JsonResponse` because the operation declares several success statuses, and a response object is how a controller sends the one it picked.',
                'throw it (`abort()` or an exception)',
            ],
        ],
        'an answer that is not JSON' => [
            [['status' => '200', 'dto' => 'UserDto'], ['status' => '204'], ['status' => '302']],
            [
                'The return type is `UserDto|Response`',
                'It admits Symfony\'s `Response` because the contract declares an answer that is not a JSON body (`204`, `302`), and only a response object sends one.',
                'throw it (`abort()` or an exception)',
            ],
        ],
        'no DTO' => [
            [['status' => '204']],
            [
                'The return type is `mixed`, because no success response of this operation has a DTO to name.',
                'It is deliberately not `never`, which would be accurate today and would forbid every future override.',
                'An error status is thrown (`abort()` or an exception), never returned.',
            ],
        ],
        'no response' => [
            [],
            ['The return type is `mixed`, because no success response of this operation has a DTO to name.'],
        ],
    ]);
});

/**
 * The `SuccessStatus` rows `callAction()` hands the runtime, one per line as written.
 *
 * @return list<string>
 */
function declaredStatusRows(string $contents): array
{
    preg_match_all('/^ {12}(new SuccessStatus\(.*\)),$/m', $contents, $found);

    return $found[1];
}

describe('the status callAction sends', function (): void {
    // Said in the file the reader opens to learn what an operation answers with, and shaped so a child that overrides
    // `routeAction` gets the status without a line of its own.
    it('writes callAction with the status each success response declares', function (): void {
        $contents = controllerAnswering([
            ['status' => '200', 'dto' => 'UserDto'],
            ['status' => '202', 'dto' => 'TeamDto'],
            ['status' => '204'],
        ]);

        expect($contents)
            ->toContain('use Gcob\\LaraSpecFirst\\Http\\SuccessStatus;')
            ->toContain('public function callAction(string $method, array $parameters): mixed')
            ->toContain('return SuccessStatus::answer(')
            ->toContain('$this->{$method}(...array_values($parameters)),')
            ->and(declaredStatusRows($contents))->toBe([
                'new SuccessStatus(200, UserDto::class)',
                'new SuccessStatus(202, TeamDto::class)',
                'new SuccessStatus(204, json: false)',
            ]);
    });

    it('sends 200 for a range or a lone default, the one number it can choose', function (string $status): void {
        expect(declaredStatusRows(controllerAnswering([['status' => $status, 'dto' => 'UserDto']])))
            ->toBe(['new SuccessStatus(200, UserDto::class)']);
    })->with([
        'a 2XX range' => ['2XX'],
        'a lowercase range' => ['2xx'],
        'a lone default' => ['default'],
    ]);

    it('carries what a returned value is matched on', function (array $rows, string $expected): void {
        expect(declaredStatusRows(controllerAnswering($rows)))->toBe([$expected]);
    })->with([
        'a list' => [[['status' => '201', 'dto' => 'UserDto', 'depth' => 1]], 'new SuccessStatus(201, UserDto::class, depth: 1)'],
        'a list of lists' => [[['status' => '201', 'dto' => 'UserDto', 'depth' => 2]], 'new SuccessStatus(201, UserDto::class, depth: 2)'],
        'a nullable body' => [[['status' => '200', 'dto' => 'UserDto', 'nullable' => true]], 'new SuccessStatus(200, UserDto::class, nullable: true)'],
        'a JSON body with no DTO' => [[['status' => '200', 'media' => ['application/json']]], 'new SuccessStatus(200)'],
        'a body that is not JSON' => [[['status' => '200', 'media' => ['text/csv']]], 'new SuccessStatus(200, json: false)'],
        'no body' => [[['status' => '204']], 'new SuccessStatus(204, json: false)'],
    ]);

    it('leaves an error status out of the table', function (): void {
        $contents = controllerAnswering([
            ['status' => '200', 'dto' => 'UserDto'],
            ['status' => '400', 'dto' => 'ProblemDto'],
            ['status' => 'default', 'dto' => 'ProblemDto'],
        ]);

        expect(declaredStatusRows($contents))->toBe(['new SuccessStatus(200, UserDto::class)']);
    });

    // The existing behavior is the right one for an operation that declares no success: Laravel's own answer.
    it('writes no callAction for an operation that declares no success status', function (array $rows): void {
        $contents = controllerAnswering($rows);

        expect($contents)->not->toContain('callAction');
        expect($contents)->not->toContain('SuccessStatus');
    })->with([
        'no response at all' => [[]],
        'an error only' => [[['status' => '400', 'dto' => 'ProblemDto']]],
        'a redirect only' => [[['status' => '302']]],
    ]);
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
            // Backticked so Pint's `fully_qualified_strict_types` leaves it
            // alone. Unquoted, the fixer rewrites it into an import plus a
            // short name — and that import would carry the child's name into
            // the parent, which shares it by design.
            ->toContain('@see `\\App\\Http\\Controllers\\UserController`')
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

/*
 * The idempotence guarantee, against the real formatter.
 *
 * The build and a consumer's formatter rewrite each other forever if the
 * emitted bytes are not already formatted, and the generated tree is excluded
 * from this repository's own Pint run — which is exactly how a docblock `@see`
 * being rewritten into an import stayed invisible until a request was emitted.
 * A committed golden file is not excluded, so the formatter judges it on every
 * run, and the import block is what is most at risk now that there is a third
 * import whose namespace the consumer chooses.
 *
 * @see tests/Fixtures/Generated/Controllers/CreateUserController.php
 */

/**
 * The controller the golden file is generated from: one that declares a request, since that is the case with an import
 * to order.
 */
function goldenController(): string
{
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody(['application/json' => new Schema(types: [SchemaType::Object])], true),
    );

    $planned = new PlannedController(
        $operation,
        ControllerName::for($operation),
        [],
        false,
        RequestName::for($operation),
    );

    return (new ControllerEmitter(
        'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\Generated',
        'tests/Fixtures/golden.yaml',
    ))->emit($planned)->contents;
}

it('reproduces the controller golden file byte for byte', function (): void {
    expect(goldenController())->toBe(file_get_contents(
        dirname(__DIR__, 2).'/Fixtures/Generated/Controllers/CreateUserController.php'
    ));
});

/**
 * The controller the typed golden file is generated from: an operation with no request, two DTO statuses and a status
 * with no body, in the namespace whose `Data/UserDto.php` and `Data/TeamDto.php` exist, so the file is a real class.
 */
function typedGoldenController(): string
{
    $operation = operationFor(
        path: '/accounts/{id}',
        operationId: 'showAccount',
        responses: [declaredResponse('200'), declaredResponse('202'), declaredResponse('204', [])],
    );

    $planned = new PlannedController(
        $operation,
        ControllerName::for($operation),
        [
            StatusResponse::of('200', 'UserDto', 0),
            StatusResponse::of('202', 'TeamDto', 0),
            StatusResponse::without('204', 'it declares no body'),
        ],
    );

    return (new ControllerEmitter(
        'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\GeneratedResponse',
        'tests/Fixtures/response-dto-golden.yaml',
    ))->emit($planned)->contents;
}

it('reproduces the typed controller golden file byte for byte', function (): void {
    expect(typedGoldenController())->toBe(file_get_contents(
        dirname(__DIR__, 2).'/Fixtures/GeneratedResponse/Controllers/ShowAccountController.php'
    ));
});

it('emits a controller Pint has nothing to change in', function (string $golden): void {
    $path = dirname(__DIR__, 2).'/Fixtures/'.$golden;

    $pint = new Process([PHP_BINARY, 'vendor/bin/pint', '--test', $path], dirname(__DIR__, 3));
    $pint->run();

    expect($pint->getExitCode())->toBe(0, $pint->getOutput().$pint->getErrorOutput());
})->with([
    'the one with a request' => ['Generated/Controllers/CreateUserController.php'],
    'the typed one' => ['GeneratedResponse/Controllers/ShowAccountController.php'],
]);

// `ordered_imports` compares with `\` replaced by a space and case folded, so a
// plain byte sort puts two namespaces on the wrong side of each other whenever
// one is a prefix of the other. The generated tree's namespace is the
// consumer's to choose, so this is not a case inspection can rule out.
it('orders imports the way the formatter orders them', function (): void {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody(['application/json' => new Schema(types: [SchemaType::Object])], true),
    );

    $planned = new PlannedController(
        $operation,
        ControllerName::for($operation),
        [],
        false,
        RequestName::for($operation),
    );

    // Sorts after `Gcob\LaraSpecFirst\Http\…` under the formatter's rule and
    // before it under a byte sort, because `\` (0x5C) is above `X` and a space
    // is below it.
    $contents = (new ControllerEmitter('Gcob\\LaraSpecFirstX', 'openapi.yaml'))->emit($planned)->contents;

    $imports = array_values(array_filter(
        explode("\n", $contents),
        static fn (string $line): bool => str_starts_with($line, 'use '),
    ));

    expect($imports)->toBe([
        'use Gcob\\LaraSpecFirst\\Exceptions\\OperationNotImplementedException;',
        'use Gcob\\LaraSpecFirst\\Http\\Controllers\\SpecController;',
        'use Gcob\\LaraSpecFirstX\\Requests\\CreateUserRequest;',
    ]);
});
