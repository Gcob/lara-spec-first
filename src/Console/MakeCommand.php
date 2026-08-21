<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Scaffolding\CustomControllerScaffold;
use Gcob\LaraSpecFirst\Scaffolding\OperationSelector;
use Gcob\LaraSpecFirst\Scaffolding\PlannedScaffold;
use Gcob\LaraSpecFirst\Scaffolding\ScaffoldPlanner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Creates the one kind of file this package does not own.
 *
 * **The only command that writes a class a developer will own, and it does it on
 * request, once.** The build never scaffolds — that is
 * [the invariant](../../docs/guide/code-generation.md#the-invariant-a-build-never-destroys-human-work)
 * rather than a preference — and a flag on the build would have made the invariant
 * conditional, which is how a hundred empty classes get committed by accident.
 *
 * **The singular form is the primitive and the other two are loops over it.**
 * `--tag` and `--all` create the same files the singular form would, one per
 * operation, because nothing this package creates is a grouped file. Two guards
 * keep the bulk forms honest: they are never the default, and they list what they
 * are about to create and ask first.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 * @see docs/guide/code-generation.md — "Scaffolding is spec:make, not a build step"
 */
final class MakeCommand extends Command
{
    use ReadsTheContract;

    /** @var string */
    protected $signature = 'spec:make
        {operation? : The operationId, or the method and path — "get /users/{id}"}
        {--tag= : Every operation the document tags with this name}
        {--all : Every operation the document describes}
        {--spec= : Read this specification instead of the configured one}';

    /** @var string */
    protected $description = 'Scaffold the custom controller an operation\'s x-controller names';

    public function handle(Repository $config, RemoteReferenceGuard $remote): int
    {
        // The same contract every command of this package keeps: a refusal is a
        // diagnostic, not a stack trace. `SpecException` is the marker that makes
        // one `catch` enough, and the messages behind it name the construct, the
        // position and the fix.
        try {
            return $this->make($config, $remote);
        } catch (SpecException $refusal) {
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }
    }

    private function make(Repository $config, RemoteReferenceGuard $remote): int
    {
        $specPath = $this->specPath($config);

        if (! is_file($specPath)) {
            $this->reportMissingSpecification($specPath);

            return self::FAILURE;
        }

        $form = $this->form();

        if ($form === null) {
            return self::FAILURE;
        }

        $namespace = rtrim($this->requiredString($config, 'lara-spec-first.generated.namespace'), '\\');
        $selector = new OperationSelector($this->contractOperations($specPath, $remote));

        $selected = match ($form) {
            'operation' => $selector->named((string) $this->argument('operation')),
            'tag' => $selector->tagged((string) $this->option('tag')),
            default => $selector->all(),
        };

        $planner = new ScaffoldPlanner($namespace);
        $planned = $planner->plan($selected);
        $undeclarable = $planner->undeclarable($selected);

        return $form === 'operation'
            ? $this->makeOne($planned, $undeclarable)
            : $this->makeMany($planned, $undeclarable);
    }

    /**
     * Which of the three forms was asked for, or null when that is not a question
     * with one answer.
     *
     * **Refused rather than resolved by precedence.** Deciding that `--all` beats
     * `--tag` would mean a mistyped invocation creating files across a whole
     * contract while the developer believed they had scoped it to one tag.
     */
    private function form(): ?string
    {
        $forms = array_keys(array_filter([
            'operation' => is_string($this->argument('operation')) && $this->argument('operation') !== '',
            'tag' => is_string($this->option('tag')) && $this->option('tag') !== '',
            'all' => (bool) $this->option('all'),
        ]));

        if (count($forms) === 1) {
            return $forms[0];
        }

        $this->components->error($forms === []
            ? 'Name what to scaffold: an operation, `--tag=Users`, or `--all`.'
            : 'Name one of an operation, `--tag=`, or `--all` — not several.');

        return null;
    }

    /**
     * The singular form: one operation, no confirmation.
     *
     * A human named one thing and one file follows, which is the shape every
     * Laravel developer already has the reflex for. The confirmation the bulk
     * forms ask for would be noise here.
     *
     * @param  list<PlannedScaffold>  $planned
     * @param  list<Operation>  $undeclarable
     */
    private function makeOne(array $planned, array $undeclarable): int
    {
        if ($planned === []) {
            $this->reportUndeclarable($undeclarable[0]);

            return self::FAILURE;
        }

        $scaffold = $planned[0];

        if ($scaffold->exists) {
            $this->components->warn(sprintf(
                '%s already exists, and a file you own is never overwritten.',
                $this->readable($scaffold->path),
            ));

            return $this->rebuild();
        }

        (new CustomControllerScaffold)->write($scaffold);

        $this->components->info(sprintf('Created %s', $this->readable($scaffold->path)));
        $this->line(sprintf(
            '  It extends %s and answers `%s`.',
            $scaffold->parent,
            $scaffold->operation->label(),
        ));

        return $this->rebuild();
    }

    /**
     * The bulk forms: list, ask, then create.
     *
     * **The listing is the guard, and the confirmation defaults to no.** Creating
     * files is this command's whole job, so bulk is legitimate — what is not is a
     * developer discovering afterwards how many files "yes" meant.
     *
     * @param  list<PlannedScaffold>  $planned
     * @param  list<Operation>  $undeclarable
     */
    private function makeMany(array $planned, array $undeclarable): int
    {
        $missing = array_values(array_filter(
            $planned,
            static fn (PlannedScaffold $scaffold): bool => ! $scaffold->exists,
        ));

        $this->reportSkipped($planned, $undeclarable);

        if ($missing === []) {
            $this->components->info('Nothing to create.');

            return $this->rebuild();
        }

        $this->components->info(sprintf('%d file(s) to create:', count($missing)));

        foreach ($missing as $scaffold) {
            $this->line(sprintf('  %s', $this->readable($scaffold->path)));
        }

        // Defaulting to no is what makes `--no-interaction` safe rather than
        // convenient: Artisan answers a prompt with its default when nobody is at
        // the keyboard, and the answer a script gets is therefore "create
        // nothing" instead of "create everything".
        if (! $this->confirm('Create them?', false)) {
            // A refusal is respected whole: no files, and no build either. The
            // build would write nothing a developer owns, but running it after
            // somebody said no is still doing work they declined.
            $this->components->warn('Nothing was created.');

            return self::SUCCESS;
        }

        $writer = new CustomControllerScaffold;

        foreach ($missing as $scaffold) {
            $writer->write($scaffold);
        }

        $this->components->info(sprintf('Created %d file(s).', count($missing)));

        return $this->rebuild();
    }

    /**
     * Build, because a scaffold on its own does not connect anything.
     *
     * **The `extends` is the reason, and it is not a nicety.** A developer adds
     * `x-controller` to an operation and runs this command: the class it names has
     * no generated parent yet, because the parent's name comes from that very
     * extension and the build has not read it. So the file this command just wrote
     * would not load, in the one moment the developer is looking at it. Running
     * the build is what makes it load — and what points the route at it, since
     * [the route's target is resolved at build time](../../docs/guide/controllers.md#two-classes-found-by-name-rather-than-by-a-scan).
     *
     * **This is not the invariant in reverse.** The rule is that the *build* never
     * creates a class a developer will own; nothing says a command that creates one
     * may not ask the build to catch up. The build writes only inside its own tree
     * either way, and it is idempotent, so the cost of running it is a read.
     *
     * Its output is left as it is rather than summarised: the routes and the
     * unimplemented count are what a developer needs to see after this, and they
     * are already the build's own words.
     */
    private function rebuild(): int
    {
        $specification = $this->option('spec');

        $this->newLine();

        return $this->call('spec:build', is_string($specification) && $specification !== ''
            ? ['--spec' => $specification]
            : []);
    }

    /**
     * What a bulk run is not going to touch, and why.
     *
     * Counted rather than listed once past a handful: a contract of two hundred
     * operations would answer `--all` with a wall, which is the trap this
     * command's own documentation warns about.
     *
     * @param  list<PlannedScaffold>  $planned
     * @param  list<Operation>  $undeclarable
     */
    private function reportSkipped(array $planned, array $undeclarable): void
    {
        $existing = count(array_filter(
            $planned,
            static fn (PlannedScaffold $scaffold): bool => $scaffold->exists,
        ));

        if ($existing > 0) {
            $this->components->info(sprintf('%d already written, and left alone.', $existing));
        }

        if ($undeclarable !== []) {
            $this->components->info(sprintf(
                '%d operation(s) declare no `x-controller`, so their generated controller is `final` '.
                'and nothing may extend it. Name one to see what to add.',
                count($undeclarable),
            ));
        }
    }

    /**
     * The singular form met an operation that cannot have a custom controller.
     *
     * The block to add is printed rather than inserted. Wanting a custom
     * controller and having to hand-edit YAML first is friction with no purpose,
     * so an insertion prompt is owed here — and until it exists, printing the
     * exact rows a developer can copy is the honest half of it.
     */
    private function reportUndeclarable(Operation $operation): void
    {
        $this->components->warn(sprintf(
            '%s declares no `x-controller`, so its generated controller is `final` and cannot be extended.',
            $operation->label(),
        ));

        $this->newLine();
        $this->line('  Add to the operation in your specification:');
        $this->newLine();
        $this->line('      x-controller: App\\Http\\Controllers\\'.$this->suggestedName($operation));
        $this->newLine();
        $this->line('  Then run this command again.');
    }

    /**
     * A class name to suggest, taken from what the document already says.
     *
     * The `operationId` when there is one, because that is a name its author
     * chose; the method and path otherwise, which is the same fallback the build
     * uses for a generated name.
     */
    private function suggestedName(Operation $operation): string
    {
        return ControllerName::for($operation)->shortName;
    }

    /**
     * A path as a reader would want to see it: relative to the project, when it is
     * inside one.
     */
    private function readable(string $path): string
    {
        $base = $this->laravel->basePath();

        return str_starts_with($path, $base.DIRECTORY_SEPARATOR)
            ? substr($path, strlen($base) + 1)
            : $path;
    }
}
