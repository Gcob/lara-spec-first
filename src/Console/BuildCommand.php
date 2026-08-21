<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;
use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Gcob\LaraSpecFirst\Support\Path;
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
 * @see docs/guide/code-generation.md — "The build command: spec:build"
 */
final class BuildCommand extends Command
{
    /** @var string */
    protected $signature = 'spec:build
        {--spec= : Read this specification instead of the configured one}';

    /** @var string */
    protected $description = 'Generate the routes and controllers your OpenAPI contract describes';

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
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }
    }

    private function build(Repository $config, RemoteReferenceGuard $remote): int
    {
        $specPath = $this->specPath($config);

        if (! is_file($specPath)) {
            $this->components->error(sprintf(
                'No specification at %s. Set `%s` in config/lara-spec-first.php, or pass --spec.',
                $specPath,
                'lara-spec-first.spec.path',
            ));

            return self::FAILURE;
        }

        $namespace = $this->requiredString($config, 'lara-spec-first.generated.namespace');

        // The guard is resolved from the container so that the configured
        // allowlist applies here exactly as it does anywhere else.
        $reader = new SpecDocumentReader(new VersionStrategyFactory, remote: $remote);

        $operations = (new OperationExtractor)->extract($reader->read($specPath));

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
        }

        return self::SUCCESS;
    }

    /**
     * A configured value this command cannot proceed without.
     *
     * Read as `mixed` and checked, rather than annotated as a string: a config
     * repository promises nothing about a key's type, and the values an
     * annotation would have declared impossible are exactly the ones worth a
     * message — a key set to null, to a list, or emptied by hand.
     *
     * @throws UnusableSettingException
     */
    private function requiredString(Repository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw UnusableSettingException::setting($key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * The flag wins over configuration, and both are resolved against the
     * application root unless they are already absolute.
     */
    private function specPath(Repository $config): string
    {
        $option = $this->option('spec');

        $path = is_string($option) && $option !== ''
            ? $option
            : $this->requiredString($config, 'lara-spec-first.spec.path');

        // `Path::isAbsolute` rather than a leading-separator check: this used to
        // ask only about `/`, which treats `C:\specs\api.yaml` as relative and
        // joins it under the application root.
        return Path::isAbsolute($path)
            ? $path
            : $this->laravel->basePath($path);
    }
}
