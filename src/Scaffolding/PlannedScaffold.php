<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\RouteActionReturn;

/**
 * One file `spec:make` intends to create, held in memory before anything is written.
 *
 * The same two-step {@see BuildPlanner} uses, for a different reason. There, planning first means a refused document
 * leaves the tree untouched; here it means the command can **list what it is about to create and ask**, which is the
 * guard that keeps a `--tag` run from being how a hundred empty classes get committed by accident.
 *
 * @see docs/guide/code-generation/scaffolding.md — "Scaffolding is spec:make, not a build step"
 */
final readonly class PlannedScaffold
{
    public RouteActionReturn $return;

    /**
     * @param  string  $class  the fully-qualified name `x-controller` gave the operation
     * @param  string  $path  where PSR-4 says that class belongs
     * @param  string  $parent  the generated class the scaffold will extend
     * @param  bool  $exists  whether a file is already there, which is the one condition that makes this scaffold a
     *                        no-op: a class the developer owns is never overwritten
     * @param  string|null  $request  the fully-qualified generated request the parent's `routeAction` declares, or null
     *                                when the operation validates nothing. The child has to declare the same parameter
     *                                or PHP refuses the override, so the scaffold cannot work it out for itself
     * @param  RouteActionReturn|null  $return  what the parent's `routeAction` returns, as the build plan worked it
     *                                          out. The child cannot widen it, so for the same reason as the request it
     *                                          is handed down and never derived again; `mixed` when there is none
     */
    public function __construct(
        public Operation $operation,
        public string $class,
        public string $path,
        public string $parent,
        public bool $exists,
        public ?string $request = null,
        ?RouteActionReturn $return = null,
    ) {
        $this->return = $return ?? RouteActionReturn::untyped();
    }
}
