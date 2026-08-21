<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Generation\GeneratedFile;
use Gcob\LaraSpecFirst\Scaffolding\CustomControllerScaffold;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnwritableScaffoldException;
use Gcob\LaraSpecFirst\Scaffolding\PlannedScaffold;

/*
 * The one file this package creates that a developer will own, which is why almost
 * every assertion here is about what it does *not* carry. A generated file explains
 * itself at length and says DO NOT EDIT; this one says the opposite by saying
 * nothing, and then gets out of the way.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */

function scaffoldTree(): string
{
    static $tree = null;

    return $tree ??= sys_get_temp_dir().'/lsf-scaffold-'.bin2hex(random_bytes(6));
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(scaffoldTree()));
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(scaffoldTree()));
});

function plannedScaffold(
    string $class = 'App\\Http\\Controllers\\UserController',
    string $path = '/Http/Controllers/UserController.php',
    string $method = 'get',
    string $template = '/users/{id}',
    ?string $operationId = 'showUser',
): PlannedScaffold {
    $operation = new Operation(
        index: 0,
        method: HttpMethod::from($method),
        path: PathTemplate::fromString($template),
        operationId: $operationId,
        controller: $class,
    );

    return new PlannedScaffold(
        $operation,
        $class,
        scaffoldTree().$path,
        'App\\Http\\Generated\\Controllers\\UserController',
        false,
    );
}

function scaffoldOf(PlannedScaffold $scaffold): string
{
    return (new CustomControllerScaffold)->contents($scaffold);
}

describe('the file it writes', function (): void {
    // The parent is named in full in the `extends` clause. Both classes share a
    // short name — that is what the two-class seam is — so an import would force
    // `use …\Generated\UserController as GeneratedUserController` into a file the
    // developer owns.
    it('extends the generated parent by its fully-qualified name', function (): void {
        $contents = scaffoldOf(plannedScaffold());

        expect($contents)
            ->toContain('class UserController extends \\App\\Http\\Generated\\Controllers\\UserController')
            ->and($contents)->not->toContain('use App\\Http\\Generated');
    });

    it('declares the namespace the class name implies', function (): void {
        expect(scaffoldOf(plannedScaffold()))->toContain('namespace App\\Http\\Controllers;');
    });

    it('declares no namespace for a class in the global one', function (): void {
        $contents = scaffoldOf(plannedScaffold(class: 'UserController'));

        expect($contents)->toContain('class UserController extends')
            ->and($contents)->not->toContain('namespace');
    });

    // What to do when PHP cannot find the parent, written once by the command that
    // creates the file. From then on it belongs to the developer, including the
    // freedom to delete it.
    it('says what to do when the parent goes missing', function (): void {
        expect(scaffoldOf(plannedScaffold()))
            ->toContain('The parent below is generated')
            ->toContain('php artisan spec:build')
            ->toContain('`x-controller` pointing here');
    });

    // A generated file says DO NOT EDIT because a build rewrites it. This file is
    // never touched again, so carrying that banner would be a lie — and carrying
    // the marker would make the pruner delete a developer's work.
    it('carries nothing that would make a build treat it as its own', function (): void {
        $contents = scaffoldOf(plannedScaffold());

        expect($contents)->not->toContain(GeneratedFile::MARKER)
            ->and($contents)->not->toContain('DO NOT EDIT')
            ->and($contents)->not->toContain('Provenance');
    });

    // Writing the method is what a developer expects from a `make`: the signature
    // is the fiddly part, PHP will not let them widen it, and copying it out of a
    // comment is work a generator should have done.
    it('writes routeAction with the signature the parent declares', function (): void {
        expect(scaffoldOf(plannedScaffold()))
            ->toContain('public function routeAction(string $id): mixed');
    });

    it('names every path parameter in the signature, in path order', function (): void {
        expect(scaffoldOf(plannedScaffold(template: '/users/{userId}/posts/{postId}')))
            ->toContain('public function routeAction(string $userId, string $postId): mixed');
    });

    /*
     * And the body is one line, calling the parent. A genuinely empty body returns
     * `null`, which Laravel renders as an empty `200` — so an empty scaffold would
     * quietly turn the operation's honest `501` into a lie, in the one command whose
     * job is to help. Replacing the line is what implementing the operation means.
     */

    it('keeps the operation answering 501 until the line is replaced', function (): void {
        expect(scaffoldOf(plannedScaffold()))
            ->toContain('return parent::routeAction($id);')
            ->toContain('Replace this line with your answer')
            ->toContain('an empty body would answer an empty 200');
    });

    it('hands the parent every parameter it declared', function (): void {
        expect(scaffoldOf(plannedScaffold(template: '/users/{userId}/posts/{postId}')))
            ->toContain('return parent::routeAction($userId, $postId);');
    });

    it('calls the parent with no arguments when the path has no parameters', function (): void {
        expect(scaffoldOf(plannedScaffold(template: '/users')))
            ->toContain('public function routeAction(): mixed')
            ->toContain('return parent::routeAction();');
    });

    it('emits PHP that parses, and the same bytes twice', function (): void {
        $scaffold = plannedScaffold();
        $contents = scaffoldOf($scaffold);
        $path = scaffoldTree().'/parses.php';

        mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
        exec('php -l '.escapeshellarg($path), $output, $status);

        expect($status)->toBe(0, implode("\n", $output))
            ->and(scaffoldOf($scaffold))->toBe($contents);
    });

    // A path is document data, and this one reaches a comment. `*/` in it would end
    // the comment early and turn what follows into a statement.
    it('cannot have its comment closed by a path from the document', function (): void {
        $contents = scaffoldOf(plannedScaffold(template: '/users/*/{id}', operationId: null));

        expect(substr_count($contents, '*/'))->toBe(0)
            ->and($contents)->toContain('*\/');
    });
});

describe('writing it', function (): void {
    it('creates the file and the directories above it', function (): void {
        $scaffold = plannedScaffold();

        (new CustomControllerScaffold)->write($scaffold);

        expect(is_file($scaffold->path))->toBeTrue()
            ->and(file_get_contents($scaffold->path))->toBe(scaffoldOf($scaffold));
    });

    // The one failure this command cannot be allowed to have. Planning happens
    // before a confirmation prompt, so a human has had time to create the file in
    // another window — and the check that prevents an overwrite has to be the one
    // immediately before the write, not the one in the plan.
    it('refuses to overwrite a file that appeared after planning', function (): void {
        $scaffold = plannedScaffold();
        $writer = new CustomControllerScaffold;

        $writer->write($scaffold);
        file_put_contents($scaffold->path, '<?php // mine');

        expect(fn () => $writer->write($scaffold))
            ->toThrow(UnwritableScaffoldException::class, 'never overwritten')
            ->and(file_get_contents($scaffold->path))->toBe('<?php // mine');
    });
});
