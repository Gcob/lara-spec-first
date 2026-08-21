<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;

/**
 * One file `spec:make` intends to create, held in memory before anything is
 * written.
 *
 * The same two-step {@see BuildPlanner} uses, for a
 * different reason. There, planning first means a refused document leaves the tree
 * untouched; here it means the command can **list what it is about to create and
 * ask**, which is the guard that keeps a `--tag` run from being how a hundred
 * empty classes get committed by accident.
 *
 * @see docs/guide/code-generation.md — "Scaffolding is spec:make, not a build step"
 */
final readonly class PlannedScaffold
{
    /**
     * @param  string  $class  the fully-qualified name `x-controller` gave the operation
     * @param  string  $path  where PSR-4 says that class belongs
     * @param  string  $parent  the generated class the scaffold will extend
     * @param  bool  $exists  whether a file is already there, which is the one
     *                        condition that makes this scaffold a no-op: a class the
     *                        developer owns is never overwritten
     */
    public function __construct(
        public Operation $operation,
        public string $class,
        public string $path,
        public string $parent,
        public bool $exists,
    ) {}
}
