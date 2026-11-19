<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\ControllerEmitter;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\PlannedController;
use Gcob\LaraSpecFirst\Generation\RouteActionSignature;
use Gcob\LaraSpecFirst\Generation\StatusResponse;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\TeamDto;
use Gcob\LaraSpecFirst\Tests\Fixtures\GeneratedResponse\Data\UserDto;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/*
 * The status an operation declares, sent by a real controller through the real router.
 *
 * The parent is what the emitter writes, loaded from a file rather than copied by hand, and the child overrides
 * `routeAction` the way a project's own controller does: without calling `parent::`. A status that arrives is
 * therefore the parent's `callAction()` having sent it, which is the only way a child that never heard of the status
 * can get it. The DTOs are the committed golden ones, so what is returned is what a build writes.
 *
 * @see docs/guide/controllers.md — "The signature is the contract"
 */

const STATUS_NAMESPACE = 'Gcob\\LaraSpecFirst\\Tests\\Fixtures\\GeneratedResponse';
const STATUS_CHILDREN = 'Gcob\\LaraSpecFirst\\Tests\\Generated\\StatusChildren';

/**
 * Register the route of an operation whose statuses are described by rows, loading its generated parent and a child
 * that overrides `routeAction` once per run.
 *
 * Each row is both what the contract declares and what the response plan made of it, so the two cannot disagree by
 * accident. A row with a `dto` is a status with a DTO, and one without is a status with none: a JSON body by default,
 * or whatever its `media` says (none at all for an empty list).
 *
 * @param  array<array{status: string, dto?: class-string, depth?: int, nullable?: bool, media?: list<string>}>  $rows
 * @return class-string the child, whose `routeAction` returns what {@see returning()} last set
 */
function answeringRoute(string $operationId, array $rows, string $method = 'get'): string
{
    $declared = [];
    $planned = [];

    foreach ($rows as $row) {
        $dto = isset($row['dto']) ? substr($row['dto'], (int) strrpos($row['dto'], '\\') + 1) : null;
        $content = [];

        foreach ($row['media'] ?? ['application/json'] as $mediaType) {
            $content[$mediaType] = new Schema(types: [SchemaType::Object]);
        }

        $declared[] = new Response($row['status'], $content);
        $planned[] = $dto === null
            ? StatusResponse::without($row['status'], 'it has no DTO')
            : StatusResponse::of($row['status'], $dto, $row['depth'] ?? 0, $row['nullable'] ?? false);
    }

    $path = '/_answers/'.$operationId;
    $short = ucfirst($operationId).'Controller';

    /** @var class-string $child */
    $child = STATUS_CHILDREN.'\\'.$short;

    Route::match([$method], $path, [$child, 'routeAction']);

    if (class_exists($child, false)) {
        return $child;
    }

    // `x-controller` makes the parent extendable, which is what lets the child exist at all.
    $operation = new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($path),
        operationId: $operationId,
        tags: [],
        controller: $child,
        responses: $declared,
    );
    $parent = new PlannedController($operation, ControllerName::for($operation), $planned);
    $return = RouteActionSignature::returns($operation, $planned, STATUS_NAMESPACE);
    $uses = implode("\n", array_map(static fn (string $class): string => 'use '.$class.';', $return->imports));

    // What a scaffolded child declares: the parent's return type, and no call to `parent::`.
    $childSource = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".STATUS_CHILDREN.";\n\n{$uses}\n\n"
        ."final class {$short} extends \\".STATUS_NAMESPACE."\\Controllers\\{$short}\n{\n"
        ."    public static ?\\Closure \$returns = null;\n\n"
        .'    public function routeAction('.RouteActionSignature::declaration($operation, null).'): '.$return->type."\n    {\n"
        ."        return (self::\$returns ?? throw new \\LogicException('nothing to return'))();\n    }\n}\n";

    $directory = sys_get_temp_dir().'/lsf-status-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $files = [$directory.'/parent.php' => (new ControllerEmitter(STATUS_NAMESPACE, 'openapi.yaml'))->emit($parent)->contents, $directory.'/child.php' => $childSource];

    register_shutdown_function(static function () use ($directory, $files): void {
        foreach (array_keys($files) as $file) {
            unlink($file);
        }

        rmdir($directory);
    });

    foreach ($files as $file => $contents) {
        file_put_contents($file, $contents);
        require_once $file;
    }

    return $child;
}

/**
 * What the child's `routeAction` returns from now on.
 *
 * @param  class-string  $child
 * @param  Closure(): mixed  $returned  built when the request arrives, so a response object is made by the framework
 *                                      that sends it
 */
function returning(string $child, Closure $returned): void
{
    (new ReflectionClass($child))->setStaticPropertyValue('returns', $returned);
}

/**
 * A request through the real kernel, to the route of an operation `answeringRoute()` registered.
 */
function answerFrom(string $operationId, string $method = 'GET'): SymfonyResponse
{
    return app(HttpKernel::class)->handle(Request::create(
        '/_answers/'.$operationId,
        $method,
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ));
}

// The status the generated parent sends, not Laravel's `200`: the child below never calls `parent::`, so only
// `callAction()` can have done it.
it('answers 201 when the operation declares only 201', function (): void {
    $child = answeringRoute('onlyCreated', [['status' => '201', 'dto' => UserDto::class]]);
    returning($child, static fn (): UserDto => new UserDto(1, 'ada@example.test'));

    $response = answerFrom('onlyCreated');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('{"id":1,"email":"ada@example.test"}');
});

it('answers the single success status for an array response', function (): void {
    $child = answeringRoute('bulkCreated', [['status' => '201', 'dto' => UserDto::class, 'depth' => 1]], 'post');
    returning($child, static fn (): array => [new UserDto(1, 'ada@example.test'), new UserDto(2, 'bob@example.test')]);

    $response = answerFrom('bulkCreated', 'POST');

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('[{"id":1,"email":"ada@example.test"},{"id":2,"email":"bob@example.test"}]');
});

// A range and `default` name no number, and `200` is the one `callAction()` can pick for them.
it('answers 200 for a 2XX range or a lone default', function (string $operationId, string $status): void {
    $child = answeringRoute($operationId, [['status' => $status, 'dto' => UserDto::class]]);
    returning($child, static fn (): UserDto => new UserDto(1, 'ada@example.test'));

    $response = answerFrom($operationId);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"id":1,"email":"ada@example.test"}');
})->with([
    'a 2XX range' => ['rangeOnly', '2XX'],
    'a lone default' => ['defaultOnly', 'default'],
]);

it('answers the first success status whose DTO was returned', function (): void {
    $child = answeringRoute('twoDtos', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '202', 'dto' => TeamDto::class],
    ]);

    returning($child, static fn (): TeamDto => new TeamDto('core'));
    $team = answerFrom('twoDtos');

    returning($child, static fn (): UserDto => new UserDto(1, 'ada@example.test'));
    $user = answerFrom('twoDtos');

    expect($team->getStatusCode())->toBe(202)
        ->and($team->getContent())->toBe('{"name":"core"}')
        ->and($user->getStatusCode())->toBe(200);

    // Two statuses for one class: the contract's order decides, and the first is `200`.
    $same = answeringRoute('sameDto', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '201', 'dto' => UserDto::class],
    ]);
    returning($same, static fn (): UserDto => new UserDto(1, 'ada@example.test'));

    expect(answerFrom('sameDto')->getStatusCode())->toBe(200);
});

// A returned list is matched on the class of its first item, so a list of teams never leaves under a status whose
// contract says a list of users. An empty list has no class and takes the first list status.
it('answers the list status whose items were returned', function (): void {
    $child = answeringRoute('listStatuses', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '201', 'dto' => UserDto::class, 'depth' => 1],
        ['status' => '202', 'dto' => TeamDto::class, 'depth' => 1],
    ]);

    returning($child, static fn (): array => [new UserDto(1, 'ada@example.test')]);
    $users = answerFrom('listStatuses');

    returning($child, static fn (): array => [new TeamDto('core')]);
    $teams = answerFrom('listStatuses');

    returning($child, static fn (): array => []);
    $empty = answerFrom('listStatuses');

    expect($users->getStatusCode())->toBe(201)
        ->and($teams->getStatusCode())->toBe(202)
        ->and($teams->getContent())->toBe('[{"name":"core"}]')
        ->and($empty->getStatusCode())->toBe(201)
        ->and($empty->getContent())->toBe('[]');

    $alone = answeringRoute('listAlone', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '201', 'dto' => UserDto::class, 'depth' => 1],
    ]);
    returning($alone, static fn (): array => [new UserDto(1, 'ada@example.test')]);

    expect(answerFrom('listAlone')->getStatusCode())->toBe(201);
});

// `new JsonResponse(null)` writes `{}`, which is not what the contract's `null` is.
it('sends a returned null as JSON null', function (string $operationId, array $rows, int $status, string $body): void {
    $child = answeringRoute($operationId, $rows);
    returning($child, static fn (): null => null);

    $response = answerFrom($operationId);

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getContent())->toBe($body);
})->with([
    'a nullable root' => ['nullableRoot', [['status' => '200', 'dto' => UserDto::class, 'nullable' => true]], 200, 'null'],
    'the first nullable status of several' => ['nullableOfSeveral', [
        ['status' => '200', 'dto' => UserDto::class, 'nullable' => true],
        ['status' => '204', 'media' => []],
    ], 200, 'null'],
    'a JSON status with no DTO' => ['jsonNoDto', [['status' => '200']], 200, 'null'],
    'a 204 with no body' => ['noBody', [['status' => '204', 'media' => []]], 204, ''],
    'a status that is not JSON' => ['csvOnly', [['status' => '200', 'media' => ['text/csv']]], 200, ''],
]);

// A child that returned `null` used to answer Laravel's empty `200` here. Ending without a `return` is a `TypeError`.
it('answers a lone JSON success whose child returns null with a JSON null, not an empty 200', function (): void {
    $child = answeringRoute('jsonNullContentType', [['status' => '200']]);
    returning($child, static fn (): null => null);

    $response = answerFrom('jsonNullContentType');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('null')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('passes any returned Response through untouched', function (string $operationId, array $rows, Closure $returned, int $status, string $body): void {
    $child = answeringRoute($operationId, $rows);
    returning($child, $returned);

    $response = answerFrom($operationId);

    // A redirect's body is Laravel's HTML page, so what identifies it is where it points.
    expect($response->getStatusCode())->toBe($status)
        ->and($response->isRedirection() ? $response->headers->get('Location') : $response->getContent())->toBe($body);
})->with([
    'a JSON response with its own status' => ['ownStatus', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '201', 'dto' => UserDto::class],
    ], static fn (): SymfonyResponse => response()->json(['id' => 1], 201), 201, '{"id":1}'],
    // Admitted by the contract's `204`: only a response object sends it.
    'noContent beside a 204' => ['noContentAdmitted', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '204', 'media' => []],
    ], static fn (): SymfonyResponse => response()->noContent(), 204, ''],
    // Admitted by the contract's `302`.
    'a redirect beside a 302' => ['redirectAdmitted', [
        ['status' => '200', 'dto' => UserDto::class],
        ['status' => '302', 'media' => []],
    ], static fn (): SymfonyResponse => redirect('/elsewhere'), 302, 'http://localhost/elsewhere'],
    // Nothing here has a DTO, so `routeAction` is `mixed` and returns whatever Laravel can turn into a response.
    'a Responsable' => ['responsableKept', [['status' => '200']], static fn (): Responsable => new class implements Responsable
    {
        public function toResponse($request): SymfonyResponse
        {
            return response('made', 202);
        }
    }, 202, 'made'],
    // A `Model` is `JsonSerializable` as a DTO is, and a recently created one keeps the `201` Laravel gives it.
    'a recently created model' => ['modelKept', [['status' => '200']], static function (): Model {
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->forceFill(['id' => 7]);
        $model->wasRecentlyCreated = true;

        return $model;
    }, 201, '{"id":7}'],
]);
