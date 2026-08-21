<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * `spec:make` through the real command, which is where the interesting half is: the
 * three forms, and the two guards that keep the bulk ones from being how a hundred
 * empty classes get committed by accident.
 *
 * The scaffolds land under a namespace this test registers with the autoloader for
 * the duration of the file, mapped into a temporary directory. That is what the
 * command asks the autoloader for — PSR-4 is how it decides where a class belongs —
 * so a test that stubbed the lookup would prove the stub answers.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */

function scaffoldRoot(): string
{
    static $root = null;

    return $root ??= sys_get_temp_dir().'/lsf-make-'.bin2hex(random_bytes(6));
}

beforeAll(function (): void {
    $root = scaffoldRoot();

    // Registered on Composer's own loader rather than with `spl_autoload_register`:
    // the command reads PSR-4 prefixes out of the ClassLoader to decide where a
    // class belongs, so a prefix it cannot see is a prefix that does not exist.
    foreach (spl_autoload_functions() ?: [] as $autoloader) {
        if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
            $autoloader[0]->setPsr4('LsfMake\\', [$root]);

            break;
        }
    }
});

/**
 * Where the build `spec:make` runs writes, kept out of the application root so one
 * test's tree can never be what another one reads.
 */
function scaffoldGeneratedTree(): string
{
    return scaffoldRoot().'/Generated';
}

beforeEach(function (): void {
    exec('rm -rf '.escapeshellarg(scaffoldRoot()));
    mkdir(scaffoldRoot(), 0o777, true);

    config()->set('lara-spec-first.generated.namespace', 'LsfMake\\Generated');
    config()->set('lara-spec-first.generated.path', scaffoldGeneratedTree());
    config()->set('lara-spec-first.spec.path', specFixturePath('scaffolding.yaml'));
});

afterAll(function (): void {
    exec('rm -rf '.escapeshellarg(scaffoldRoot()));
});

/**
 * Run the command and hand back its output, whatever it decided.
 *
 * @param  array<string, mixed>  $arguments
 */
function make(array $arguments = [], ?int $expected = null): string
{
    $output = new BufferedOutput;
    $status = app(Kernel::class)->call('spec:make', $arguments, $output);

    if ($expected !== null) {
        expect($status)->toBe($expected);
    }

    return $output->fetch();
}

/**
 * @return list<string> the basenames of every scaffolded controller
 */
function scaffoldedFiles(): array
{
    $found = glob(scaffoldRoot().'/Http/Controllers/*.php') ?: [];

    return array_map(static fn (string $path): string => basename($path), $found);
}

describe('the singular form', function (): void {
    it('creates the class the contract names, for the operation named', function (): void {
        $output = make(['operation' => 'showUser'], 0);

        expect(scaffoldedFiles())->toBe(['UserController.php'])
            ->and($output)->toContain('Created');
    });

    it('writes a class that extends the generated parent for that operation', function (): void {
        make(['operation' => 'showUser']);

        expect(file_get_contents(scaffoldRoot().'/Http/Controllers/UserController.php'))
            ->toContain('namespace LsfMake\\Http\\Controllers;')
            ->toContain('class UserController extends \\LsfMake\\Generated\\Controllers\\UserController')
            ->toContain('public function routeAction(string $id): mixed')
            ->toContain('return parent::routeAction($id);');
    });

    it('takes the method and path when the operation has no operationId', function (): void {
        make(['operation' => 'delete /legacy'], 0);

        expect(scaffoldedFiles())->toBe(['LegacyController.php']);
    });

    // Never overwritten, and it succeeds rather than failing: the developer asked
    // for a file to exist and it does.
    it('leaves a file that already exists alone', function (): void {
        make(['operation' => 'showUser']);
        file_put_contents(scaffoldRoot().'/Http/Controllers/UserController.php', '<?php // mine');

        $output = make(['operation' => 'showUser'], 0);

        expect($output)->toContain('already exists')
            ->and(file_get_contents(scaffoldRoot().'/Http/Controllers/UserController.php'))
            ->toBe('<?php // mine');
    });

    // Nothing to extend, so nothing to scaffold. What the command owes instead is
    // the row to add, because wanting a custom controller and having to go read the
    // documentation to learn the key's name is friction with no purpose.
    it('prints the row to add when the operation declares no x-controller', function (): void {
        $output = make(['operation' => 'listPosts'], 1);

        expect($output)
            ->toContain('declares no `x-controller`')
            ->toContain('x-controller: App\\Http\\Controllers\\ListPostsController')
            ->and(scaffoldedFiles())->toBe([]);
    });

    it('refuses a name the contract does not carry, and writes nothing', function (): void {
        $output = make(['operation' => 'noSuchOperation'], 1);

        expect($output)->toContain('No operation in the specification is named')
            ->and(scaffoldedFiles())->toBe([]);
    });
});

describe('the bulk forms', function (): void {
    // The listing is the guard: creating files is this command's job, and what is
    // not acceptable is a developer discovering afterwards how many files "yes"
    // meant.
    it('lists what it would create before asking', function (): void {
        $output = make(['--tag' => 'Users', '--no-interaction' => true], 0);

        expect($output)
            ->toContain('file(s) to create')
            ->toContain('UserController.php');
    });

    // Defaulting to no is what makes a non-interactive run safe rather than
    // convenient: Artisan answers a prompt with its default when nobody is at the
    // keyboard, so a script gets "create nothing".
    it('creates nothing when nobody is there to confirm', function (): void {
        $output = make(['--all' => true, '--no-interaction' => true], 0);

        expect($output)->toContain('Nothing was created')
            ->and(scaffoldedFiles())->toBe([]);
    });

    it('says how many operations it cannot scaffold, and why', function (): void {
        expect(make(['--all' => true, '--no-interaction' => true], 0))
            ->toContain('declare no `x-controller`');
    });

    // The tag holds two operations, so one written already means one left: the
    // count a developer reads has to be of what will happen, not of what was
    // selected.
    it('leaves what is already written alone rather than counting it as work', function (): void {
        make(['operation' => 'showUser']);

        expect(make(['--tag' => 'Users', '--no-interaction' => true], 0))
            ->toContain('1 already written, and left alone')
            ->toContain('1 file(s) to create')
            ->toContain('LegacyController.php');
    });

    it('says there is nothing to create once every class exists', function (): void {
        make(['operation' => 'showUser']);
        make(['operation' => 'delete /legacy']);

        expect(make(['--tag' => 'Users', '--no-interaction' => true], 0))
            ->toContain('2 already written, and left alone')
            ->toContain('Nothing to create');
    });

    it('refuses a tag the contract does not carry', function (): void {
        expect(make(['--tag' => 'Nope', '--no-interaction' => true], 1))
            ->toContain('carries the tag "Nope"');
    });
});

describe('naming what to scaffold', function (): void {
    // Refused rather than resolved by precedence: deciding that `--all` beats
    // `--tag` would mean a mistyped invocation creating files across a whole
    // contract while the developer believed they had scoped it.
    it('refuses two forms at once', function (): void {
        expect(make(['--tag' => 'Users', '--all' => true], 1))
            ->toContain('not several');
    });

    it('refuses none at all', function (): void {
        expect(make([], 1))->toContain('Name what to scaffold');
    });
});

it('reports a missing specification the way the build does', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('does-not-exist.yaml'));

    expect(make(['--all' => true], 1))->toContain('No specification at');
});

/*
 * And then it builds, which is the half that makes the file it just wrote work.
 *
 * A developer adds `x-controller` and runs this command: the class it names has no
 * generated parent yet, because the parent's name comes from that very extension
 * and the build has not read it. So the `extends` would point at nothing, in the
 * one moment the developer is looking at the file.
 */

it('builds afterwards, so the class it wrote has a parent to extend', function (): void {
    make(['operation' => 'showUser'], 0);

    expect(is_file(scaffoldGeneratedTree().'/Controllers/UserController.php'))->toBeTrue();
});

// And the route reaches the child rather than the parent, which is resolved at
// build time — so without that build it would have kept pointing at the parent
// until somebody ran one.
it('leaves the routes pointing at the class it created', function (): void {
    make(['operation' => 'showUser']);

    expect(file_get_contents(scaffoldGeneratedTree().'/routes.php'))
        ->toContain('use LsfMake\\Http\\Controllers\\UserController;')
        ->toContain("Route::get('/users/{id}', [UserController::class, 'routeAction']);");
});

it('prints the build\'s own report rather than summarising it', function (): void {
    expect(make(['operation' => 'showUser']))
        ->toContain('operation(s) built')
        ->toContain('have no implementation and answer 501');
});

// A refusal is respected whole: the build writes nothing a developer owns, but
// running it after somebody said no is still doing work they declined.
it('does not build when the bulk confirmation was declined', function (): void {
    make(['--all' => true, '--no-interaction' => true], 0);

    expect(is_dir(scaffoldGeneratedTree()))->toBeFalse();
});

// Nothing was created, but the tree is still brought up to date: the developer
// asked for a state to be true, and the class existing without the routes
// reaching it is not that state.
it('builds even when every class was already there', function (): void {
    make(['operation' => 'showUser']);
    exec('rm -rf '.escapeshellarg(scaffoldGeneratedTree()));

    make(['operation' => 'showUser'], 0);

    expect(is_file(scaffoldGeneratedTree().'/routes.php'))->toBeTrue();
});
