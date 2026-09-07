<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Generation\BuildPlan;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;
use Gcob\LaraSpecFirst\Generation\OperationSelector;
use Gcob\LaraSpecFirst\Generation\PlannedController;
use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * Turns the specification into the PHP that serves it.
 *
 * **Ordered, and it stops.** Read the document, extract its operations, plan
 * every file in memory, and only then write. Nothing reaches the disk until the
 * whole contract has been understood, so a document this package refuses leaves
 * the working tree exactly as it was rather than half generated.
 *
 * What it emits in this form is the routes and one controller per operation.
 * Response DTOs and request validation are the same command doing more later, not
 * a second command.
 *
 * @see docs/guide/code-generation/index.md — "The build command: spec:build"
 */
final class BuildCommand extends Command
{
    use ReadsTheContract;

    /** @var string */
    protected $signature = 'spec:build
        {--spec= : Read this specification instead of the configured one}
        {--update-refs : Fetch and vendor any remote reference the specification names}';

    /** @var string */
    protected $description = 'Generate the routes and controllers your OpenAPI contract describes';

    /**
     * How many tags the unimplemented summary names before it counts the rest.
     */
    private const int TAGS_SHOWN = 5;

    public function handle(Repository $config, RemoteReferenceGuard $remote): int
    {
        // Every refusal this package raises implements `SpecException`, and that
        // marker exists so a consumer can catch the lot with one clause. This is
        // the first place that promise pays for itself: the messages are written
        // to be read — they name the construct, the position and the fix — and
        // rendering them as an uncaught exception with a source excerpt would
        // throw all of that away in the command that is meant to be the contract's
        // `nginx -t`.
        try {
            return $this->build($config, $remote);
        } catch (SpecException $refusal) {
            // Rendered through the same method `operationsOrFail()` uses, so a
            // fault caught off a throw and a fault read off a `ReadOutcome`
            // reach a developer identically — see
            // {@see ReadsTheContract::reportFault()}.
            return $this->reportFault($refusal);
        }
    }

    private function build(Repository $config, RemoteReferenceGuard $remote): int
    {
        $specPath = $this->specPath($config);

        if (! is_file($specPath)) {
            $this->reportMissingSpecification($specPath);

            return self::FAILURE;
        }

        $namespace = $this->requiredString($config, 'lara-spec-first.generated.namespace');

        $operations = $this->operationsOrFail($specPath, $remote, (bool) $this->option('update-refs'));

        if ($operations === null) {
            return self::FAILURE;
        }

        // Named from the project root rather than absolutely: a generated file
        // may end up committed, and a machine's path in a repository is a diff
        // nobody made.
        $plan = (new BuildPlanner(rtrim($namespace, '\\'), ProjectRelativePath::from($specPath)))
            ->plan($operations);

        $configured = $config->get(GeneratedRoutesLocator::SETTING);
        $locator = GeneratedRoutesLocator::fromConfiguration($this->laravel->basePath(), $configured);

        $report = (new GeneratedTree(dirname($locator->path())))->write($plan->files);

        $this->components->info($report->changedNothing()
            ? sprintf('%d operation(s) built. Already up to date.', count($operations))
            : sprintf(
                '%d operation(s) built. %d file(s) written, %d unchanged, %d pruned.',
                count($operations),
                $report->written,
                $report->unchanged,
                $report->pruned,
            ));

        // An operation nothing implements answers 501, and saying so is the point
        // rather than a caveat: a contract that describes endpoints nothing
        // implements should not read as a finished application. Counted from the
        // plan rather than from the operations, because an operation whose
        // `x-controller` class exists is answered by that class — and warning
        // about it would tell a developer their own controller does not count.
        // True while a generated parent answers 501 and nothing else. When a
        // generated controller can answer an operation from a CRUD default, this
        // is the line to revisit — {@see BuildPlan::routedToGeneratedParent()}
        // counts routes rather than making the claim itself.
        $unimplemented = $plan->routedToGeneratedParent();

        if ($unimplemented > 0) {
            $this->components->warn(sprintf(
                '%d of %d operation(s) have no implementation and answer 501.',
                $unimplemented,
                count($operations),
            ));

            $this->nameTheCommand($plan);
        }

        return self::SUCCESS;
    }

    /**
     * Name the command that implements an operation, rather than running it.
     *
     * **The build never scaffolds**, so what it owes instead is ergonomics: the
     * exact invocation, ready to copy. Printing one line per operation is the trap
     * — a specification with two hundred unimplemented operations would answer
     * with two hundred commands, which is not a list but a wall, arriving on the
     * day somebody adopts this package.
     *
     * **So it summarises by `tags`**, because the specification already carries
     * the author's own grouping and inventing a second one would be worse than
     * using theirs. This groups the printed list only; every file `spec:make`
     * creates is still one controller for one operation.
     *
     * @see docs/guide/code-generation/scaffolding.md — "The build names the command instead of running it"
     */
    private function nameTheCommand(BuildPlan $plan): void
    {
        $waiting = array_values(array_map(
            static fn (PlannedController $controller): Operation => $controller->operation,
            array_filter(
                $plan->controllers,
                static fn (PlannedController $controller): bool => ! $controller->routesToCustomController(),
            ),
        ));

        $selector = new OperationSelector($waiting);
        $counts = $selector->countsByTag();
        $shown = array_slice($counts, 0, self::TAGS_SHOWN, true);

        // Padded to one width so the commands line up in a column: the point of
        // the summary is that a reader's eye finds the invocation, and a ragged
        // left edge is what makes a list of commands read as prose.
        $labels = [];

        foreach ($shown as $tag => $count) {
            $labels[$tag] = sprintf('%s (%d)', $tag, $count);
        }

        $untagged = $selector->untagged();

        if ($untagged !== []) {
            $labels[''] = sprintf('untagged (%d)', count($untagged));
        }

        $column = max(array_map(mb_strlen(...), $labels === [] ? [''] : $labels));

        foreach ($shown as $tag => $count) {
            $this->line(sprintf(
                '  %s   php artisan spec:make --tag=%s',
                str_pad($labels[$tag], $column),
                $tag,
            ));
        }

        $remaining = count($counts) - count($shown);

        if ($remaining > 0) {
            $this->line(sprintf('  ... and %d more tag(s).', $remaining));
        }

        // An untagged operation is reachable by neither `--tag` nor a grouping, so
        // the atomic form is named for it — with one operation's own name, which is
        // what a reader can act on without going to look for one.
        if ($untagged !== []) {
            $this->line(sprintf(
                '  %s   php artisan spec:make %s',
                str_pad($labels[''], $column),
                $this->nameOf($untagged[0]),
            ));
        }
    }

    /**
     * How a developer would name this operation to `spec:make`.
     *
     * The `operationId` when it has one, and its method and path otherwise —
     * quoted, because a path carries characters a shell would otherwise read.
     */
    private function nameOf(Operation $operation): string
    {
        return $operation->operationId ?? '"'.$operation->label().'"';
    }
}
