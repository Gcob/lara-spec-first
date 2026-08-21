<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
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
    config()->set('lara-spec-first.make.controllers', 'LsfMake\\Http\\Controllers');
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

    /*
     * Nothing to extend, so nothing to scaffold — and the command offers to add the
     * row rather than leaving a developer to learn the key's name from the
     * documentation. The value is derived: the configured controller namespace plus
     * the name the build would have generated anyway.
     *
     * Non-interactive here, which is how these tests decline: Artisan answers a
     * prompt with its default, and the default is no.
     */
    it('offers the row to add when the operation declares no x-controller', function (): void {
        $output = make(['operation' => 'listPosts', '--no-interaction' => true], 1);

        expect($output)
            ->toContain('declares no `x-controller`')
            ->toContain('x-controller: LsfMake\\Http\\Controllers\\ListPostsController')
            ->toContain('Nothing was written')
            ->and(scaffoldedFiles())->toBe([]);
    });

    it('names the line the row would go on', function (): void {
        expect(make(['operation' => 'listPosts', '--no-interaction' => true], 1))
            ->toContain('scaffolding.yaml, line 25');
    });

    // Declining leaves the document exactly as it was, which is the property that
    // makes the offer safe to make at all.
    it('writes nothing to the specification when the offer is declined', function (): void {
        $before = file_get_contents(specFixturePath('scaffolding.yaml'));

        make(['operation' => 'listPosts', '--no-interaction' => true], 1);

        expect(file_get_contents(specFixturePath('scaffolding.yaml')))->toBe($before);
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

/*
 * And the other half of the offer: answering yes.
 *
 * Driven through the console kernel with an input stream holding the answer, rather
 * than through `$this->artisan()`. The helper reads better, and it is typed as a
 * pending test call rather than as the test case — so static analysis cannot see the
 * method, and this file would need an exemption to use it. An `ArrayInput` with a
 * stream is what Symfony's question helper reads from anyway.
 *
 * The specification is a copy under the temporary root: a test that edited the
 * fixture would be a test that only passes once.
 */

/**
 * Run the command with somebody at the keyboard, typing what they are told to.
 *
 * The prompt is a value rather than a yes or no, so what is piped here is the class
 * name a developer would have submitted.
 *
 * @param  array<string, mixed>  $arguments
 */
function makeAnswering(array $arguments, string $answer, int $expected): string
{
    $stream = fopen('php://memory', 'r+');

    if ($stream === false) {
        throw new RuntimeException('cannot open an input stream for the confirmation');
    }

    fwrite($stream, $answer."\n");
    rewind($stream);

    $input = new ArrayInput(['command' => 'spec:make'] + $arguments);
    $input->setInteractive(true);
    $input->setStream($stream);

    $output = new BufferedOutput;

    expect(app(Kernel::class)->handle($input, $output))->toBe($expected);

    return $output->fetch();
}

function editableSpecification(): string
{
    $path = scaffoldRoot().'/openapi.yaml';

    copy(specFixturePath('scaffolding.yaml'), $path);
    config()->set('lara-spec-first.spec.path', $path);

    return $path;
}

it('writes the row into the specification when a name is submitted', function (): void {
    $path = editableSpecification();

    makeAnswering(['operation' => 'listPosts'], 'LsfMake\\Http\\Controllers\\ListPostsController', 0);

    expect(file_get_contents($path))
        ->toContain("        get:\n            x-controller: LsfMake\\Http\\Controllers\\ListPostsController\n");
});

// The whole point of the prompt existing: one command takes a developer from an
// operation the contract says nothing about to a class the route reaches.
it('scaffolds and builds once the extension is in', function (): void {
    editableSpecification();

    makeAnswering(['operation' => 'listPosts'], 'LsfMake\\Http\\Controllers\\ListPostsController', 0);

    expect(is_file(scaffoldRoot().'/Http/Controllers/ListPostsController.php'))->toBeTrue()
        ->and(file_get_contents(scaffoldGeneratedTree().'/routes.php'))
        ->toContain('use LsfMake\\Http\\Controllers\\ListPostsController;');
});

// The document keeps its comments, because nothing round-trips it through a dumper.
it('leaves the document it edited otherwise untouched', function (): void {
    $path = editableSpecification();
    $before = (string) file_get_contents($path);

    makeAnswering(['operation' => 'listPosts'], 'LsfMake\\Http\\Controllers\\ListPostsController', 0);

    $after = (string) file_get_contents($path);

    expect($after)->toContain('# The contract `spec:make` is exercised against')
        ->and(substr_count($after, "\n"))->toBe(substr_count($before, "\n") + 1);
});

// The name a developer types is the one that lands, which is the whole reason the
// prompt is a value rather than a question about somebody else's choice.
it('uses the name that was typed rather than the one proposed', function (): void {
    $path = editableSpecification();

    makeAnswering(['operation' => 'listPosts'], 'LsfMake\\Http\\Controllers\\Posts\\FeedController', 0);

    expect(file_get_contents($path))
        ->toContain('x-controller: LsfMake\\Http\\Controllers\\Posts\\FeedController')
        ->and(is_file(scaffoldRoot().'/Http/Controllers/Posts/FeedController.php'))->toBeTrue();
});

// A document whose operation the locator cannot place is still a document this
// package builds from, so the command prints the row and says why it did not offer.
it('prints the row without offering when it cannot place the line', function (): void {
    config()->set('lara-spec-first.spec.path', specFixturePath('openapi-3.1.json'));

    $output = make(['operation' => 'listUsers', '--no-interaction' => true], 1);

    expect($output)
        ->toContain('could not work out where that line goes')
        ->toContain('x-controller: LsfMake\\Http\\Controllers\\ListUsersController');
});

/*
 * `--yes`, for a developer who knows what they want and does not want to be asked.
 *
 * It answers every question with the answer the command proposed, and changes no
 * other guard: the insertion still verifies itself, a file that exists is still left
 * alone, and a name this project could not place is still refused.
 */

it('takes the proposed name without asking', function (): void {
    $path = editableSpecification();

    make(['operation' => 'listPosts', '--yes' => true, '--no-interaction' => true], 0);

    expect(file_get_contents($path))
        ->toContain('x-controller: LsfMake\\Http\\Controllers\\ListPostsController')
        ->and(is_file(scaffoldRoot().'/Http/Controllers/ListPostsController.php'))->toBeTrue();
});

it('creates a whole tag without asking', function (): void {
    make(['--tag' => 'Users', '--yes' => true, '--no-interaction' => true], 0);

    expect(scaffoldedFiles())->toBe(['LegacyController.php', 'UserController.php']);
});

// A flag that says yes is not a flag that says do it anyway: an unusable proposal is
// refused, and the specification is left as it was.
it('refuses a proposal this project could not place, and writes nothing', function (): void {
    $path = editableSpecification();
    $before = file_get_contents($path);

    config()->set('lara-spec-first.make.controllers', 'Acme\\Nowhere');

    $output = make(['operation' => 'listPosts', '--yes' => true, '--no-interaction' => true], 1);

    expect($output)->toContain('cannot be written')
        ->and(file_get_contents($path))->toBe($before)
        ->and(scaffoldedFiles())->toBe([]);
});

// And it still never overwrites: the file exists, so the command says so and moves on
// to the build rather than replacing what the developer wrote.
it('leaves an existing file alone even when told yes', function (): void {
    make(['operation' => 'showUser']);
    file_put_contents(scaffoldRoot().'/Http/Controllers/UserController.php', '<?php // mine');

    make(['operation' => 'showUser', '--yes' => true, '--no-interaction' => true], 0);

    expect(file_get_contents(scaffoldRoot().'/Http/Controllers/UserController.php'))->toBe('<?php // mine');
});
