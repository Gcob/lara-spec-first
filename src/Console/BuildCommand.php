<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;
use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
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
        $specPath = $this->specPath($config);

        if (! is_file($specPath)) {
            $this->components->error(sprintf(
                'No specification at %s. Set `%s` in config/lara-spec-first.php, or pass --spec.',
                $specPath,
                'lara-spec-first.spec.path',
            ));

            return self::FAILURE;
        }

        /** @var string $namespace */
        $namespace = $config->get('lara-spec-first.generated.namespace');

        // The guard is resolved from the container so that the configured
        // allowlist applies here exactly as it does anywhere else.
        $reader = new SpecDocumentReader(new VersionStrategyFactory, remote: $remote);

        $operations = (new OperationExtractor)->extract($reader->read($specPath));

        // Named from the project root rather than absolutely: a generated file
        // may end up committed, and a machine's path in a repository is a diff
        // nobody made.
        $files = (new BuildPlanner(rtrim($namespace, '\\'), ProjectRelativePath::from($specPath)))
            ->plan($operations);

        $configured = $config->get(GeneratedRoutesLocator::SETTING);
        $locator = GeneratedRoutesLocator::fromConfiguration($this->laravel->basePath(), $configured);

        $report = (new GeneratedTree(dirname($locator->path())))->write($files);

        $this->components->info($report->changedNothing()
            ? sprintf('%d operation(s) built. Already up to date.', count($operations))
            : sprintf(
                '%d operation(s) built. %d file(s) written, %d unchanged, %d pruned.',
                count($operations),
                $report->written,
                $report->unchanged,
                $report->pruned,
            ));

        // Every operation answers 501 in this form of the build, and saying so is
        // the point rather than a caveat: a contract that describes endpoints
        // nothing implements should not read as a finished application.
        if ($operations !== []) {
            $this->components->warn(sprintf(
                '%d operation(s) have no implementation and answer 501.',
                count($operations),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * The flag wins over configuration, and both are resolved against the
     * application root unless they are already absolute.
     */
    private function specPath(Repository $config): string
    {
        $option = $this->option('spec');

        /** @var string $path */
        $path = is_string($option) && $option !== ''
            ? $option
            : $config->get('lara-spec-first.spec.path');

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->laravel->basePath($path);
    }
}
