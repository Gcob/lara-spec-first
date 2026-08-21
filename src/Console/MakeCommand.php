<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;
use Gcob\LaraSpecFirst\Generation\OperationSelector;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Scaffolding\CustomControllerName;
use Gcob\LaraSpecFirst\Scaffolding\CustomControllerScaffold;
use Gcob\LaraSpecFirst\Scaffolding\Exceptions\UnplaceableClassException;
use Gcob\LaraSpecFirst\Scaffolding\ExtensionInsertion;
use Gcob\LaraSpecFirst\Scaffolding\OperationLocator;
use Gcob\LaraSpecFirst\Scaffolding\PlannedScaffold;
use Gcob\LaraSpecFirst\Scaffolding\ScaffoldPlanner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\text;

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
        {--yes : Take the proposed x-controller and skip every confirmation}
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
        $selected = $this->select($form, $specPath, $remote);

        // The one operation the singular form named may have no `x-controller` yet,
        // and offering to add it is the whole point of this command being the way
        // in. Once the document carries it, everything below runs as though it
        // always had — read again, because the contract is what decides, and it
        // just changed.
        if ($form === 'operation' && ($selected[0] ?? null) !== null && $selected[0]->controller === null) {
            if (! $this->offerTheExtension($config, $remote, $specPath, $selected[0])) {
                return self::FAILURE;
            }

            $selected = $this->select($form, $specPath, $remote);
        }

        // Shared with the planner and the writer both, so the two ask the
        // autoloader the same question at the same moment: existence, once
        // Composer's negative-lookup cache has been read, does not change again
        // during this command's own run.
        $lookup = CustomControllerLookup::fromAutoloader();
        $planner = new ScaffoldPlanner($namespace, $lookup);
        $planned = $planner->plan($selected);
        $undeclarable = $planner->undeclarable($selected);
        $unplaceable = $planner->unplaceable($selected);

        if ($form === 'operation') {
            // The singular form named one operation, so an unplaceable
            // `x-controller` is refused outright rather than collected: there is
            // nothing else in the selection to report it beside.
            if ($unplaceable !== []) {
                throw UnplaceableClassException::forClass(
                    $selected[0]->label(),
                    (string) $selected[0]->controller,
                );
            }

            return $this->makeOne($planned, $lookup);
        }

        return $this->makeMany($planned, $undeclarable, $unplaceable, $lookup);
    }

    /**
     * The operations the form named, read from the document as it is now.
     *
     * @return list<Operation> never empty: every form refuses rather than selecting
     *                         nothing, so a caller reading `[0]` is safe
     */
    private function select(string $form, string $specPath, RemoteReferenceGuard $remote): array
    {
        $selector = new OperationSelector($this->contractOperations($specPath, $remote));

        return match ($form) {
            'operation' => $selector->named((string) $this->argument('operation')),
            'tag' => $selector->tagged((string) $this->option('tag')),
            default => $selector->all(),
        };
    }

    /**
     * Why the flag is `--yes` rather than `--force`.
     *
     * Laravel's own generators use `--force` to mean *overwrite what is there*, and
     * this command never overwrites a file a developer owns. Borrowing the word
     * would promise the one thing it refuses. `--yes` says what it does: every
     * question this command would have asked is answered with the answer it
     * proposed.
     *
     * It changes no guard except the asking. The insertion still verifies itself on
     * a copy, a file that exists is still left alone, and a name the project could
     * not place is still refused — loudly, because a flag that says yes is not a
     * flag that says do it anyway.
     *
     * **The one guard it does deliberately answer for is "nobody is here to name
     * the class".** `--yes` is itself that name, given on the command line rather
     * than at a prompt, so it edits the specification even under
     * `--no-interaction` — where the same run without it would print the row and
     * stop. That is the point of the flag existing at all.
     */
    private function saysYes(): bool
    {
        return (bool) $this->option('yes');
    }

    /**
     * Say the extension is missing, propose a name, and let the developer edit it.
     *
     * **A value rather than a yes-or-no**, and that is a revision worth naming. The
     * question used to be "insert this? [y/N]", which asked a developer to approve a
     * class name they had no way to change — so the only way to use their own was to
     * answer no, open the document and type it. The prompt is prefilled with the
     * proposal instead: submitting it accepts, editing it uses theirs, and an empty
     * submission leaves the document alone.
     *
     * **Nothing is written without a person there, or `--yes` standing in for
     * one.** A non-interactive run with no `--yes` prints the row and stops,
     * because Artisan would otherwise answer the prompt with its own default and
     * a script would edit a specification nobody agreed to edit. `--yes` is that
     * agreement, given up front on the command line, so it edits even under
     * `--no-interaction` — {@see self::saysYes()} for why that is not a
     * contradiction.
     *
     * **The proposal is derived rather than asked for.** It is the configured
     * controller namespace plus the name the build would have generated anyway, so
     * the common case is a keystroke. What ends up in the document is what was
     * submitted; from then on the document decides, and the configured namespace
     * never renames it.
     *
     * @return bool whether the document now carries the extension
     */
    private function offerTheExtension(
        Repository $config,
        RemoteReferenceGuard $remote,
        string $specPath,
        Operation $operation,
    ): bool {
        $name = new CustomControllerName(
            $this->requiredString($config, 'lara-spec-first.make.controllers'),
            $this->requiredString($config, 'lara-spec-first.generated.namespace'),
        );

        $value = $name->propose($operation);

        // Stated as the missing input it is, rather than as a warning about the
        // consequence. A developer who typed this command wants a controller; that
        // the generated one is `final` until the contract says otherwise is the
        // reason, and the reason belongs in the documentation and in the generated
        // file's own findings, not in the way of the thing they asked for.
        $this->components->info(sprintf(
            '%s declares no `x-controller`, which is what makes an operation customizable.',
            $operation->label(),
        ));

        $location = (new OperationLocator)->locate((string) @file_get_contents($specPath), $operation);
        $row = 'x-controller: '.$value;

        if ($location === null) {
            // Every shape the locator cannot read with certainty lands here: a
            // flow-style mapping, a JSON document, an operation reached through a
            // reference into another file. Each is a document this package still
            // builds from, and none is one it may edit blind.
            $this->newLine();
            $this->line('  Add this to the operation in '.$this->readable($specPath).':');
            $this->newLine();
            $this->line('      '.$row);
            $this->newLine();
            $this->line('  This command could not work out where that line goes in your document, so');
            $this->line('  it has not offered to write it. Add it by hand and run this again.');

            return false;
        }

        $this->newLine();
        $this->line(sprintf(
            '  It goes in %s, line %d.',
            $this->readable($specPath),
            $location->line + 1,
        ));

        if ($this->saysYes()) {
            $refusal = $name->reasonToRefuse($value);

            if ($refusal !== null) {
                $this->components->error(sprintf(
                    'The proposed `%s` cannot be written: %s',
                    $value,
                    $refusal,
                ));

                return false;
            }

            (new ExtensionInsertion($remote))->insert($specPath, $operation, $location, $value);

            $this->components->info(sprintf(
                'Added `x-controller: %s` to %s.',
                $value,
                $this->readable($specPath),
            ));

            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->newLine();
            $this->line('      '.$location->indentation.$row);
            $this->newLine();
            $this->line('  Nothing was written, because nobody is here to name the class. Add the row');
            $this->line('  above and run this command again.');

            return false;
        }

        $this->newLine();

        // A leading `\` is accepted and dropped rather than refused — the
        // extractor does the same on read — so it is normalized here, once,
        // rather than left for `ExtensionInsertion` to compare a raw submission
        // against an extractor that already stripped it.
        $chosen = ltrim(trim((string) text(
            label: 'x-controller',
            default: $value,
            hint: 'Edit it, or submit it empty to leave the specification alone.',
            validate: $name->reasonToRefuse(...),
        )), '\\');

        if ($chosen === '') {
            $this->line('  Nothing was written. Add the row yourself and run this command again.');

            return false;
        }

        (new ExtensionInsertion($remote))->insert($specPath, $operation, $location, $chosen);

        $this->components->info(sprintf(
            'Added `x-controller: %s` to %s.',
            $chosen,
            $this->readable($specPath),
        ));

        return true;
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
     */
    private function makeOne(array $planned, CustomControllerLookup $lookup): int
    {
        $scaffold = $planned[0];

        if ($scaffold->exists) {
            $this->components->warn(sprintf(
                '%s already exists, and a file you own is never overwritten.',
                $this->readable($scaffold->path),
            ));

            return $this->rebuild();
        }

        (new CustomControllerScaffold($lookup))->write($scaffold);

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
     * @param  list<Operation>  $unplaceable
     */
    private function makeMany(
        array $planned,
        array $undeclarable,
        array $unplaceable,
        CustomControllerLookup $lookup,
    ): int {
        $missing = array_values(array_filter(
            $planned,
            static fn (PlannedScaffold $scaffold): bool => ! $scaffold->exists,
        ));

        $this->reportSkipped($planned, $undeclarable, $unplaceable);

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
        if (! $this->saysYes() && ! $this->confirm('Create them?', false)) {
            // A refusal is respected whole: no files, and no build either. The
            // build would write nothing a developer owns, but running it after
            // somebody said no is still doing work they declined.
            $this->components->warn('Nothing was created.');

            return self::SUCCESS;
        }

        $writer = new CustomControllerScaffold($lookup);

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
     * the build is what makes it load — and what points the route at it, since the
     * route's target is resolved at build time.
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
     * @param  list<Operation>  $unplaceable
     */
    private function reportSkipped(array $planned, array $undeclarable, array $unplaceable): void
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

        if ($unplaceable !== []) {
            $this->components->warn(sprintf(
                '%d operation(s) declare an `x-controller` in a namespace no PSR-4 prefix in this '.
                'project maps, so nothing was scaffolded for them. Name one to see which.',
                count($unplaceable),
            ));
        }
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
