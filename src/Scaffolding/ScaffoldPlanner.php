<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\ControllerName;
use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;

/**
 * Works out which files `spec:make` would create for a set of operations.
 *
 * **The selection is somebody else's job and the writing is somebody else's job.**
 * What is decided here is only what the answer *would* be — which class, at which
 * path, extending which parent, and whether it is already there — so that the
 * command can print that answer and ask before any of it happens.
 *
 * @see docs/guide/code-generation.md — "Scaffolding is spec:make, not a build step"
 */
final readonly class ScaffoldPlanner
{
    private CustomControllerLookup $lookup;

    /**
     * @param  string  $namespace  the configured generated root namespace, which is
     *                             where the parent a scaffold extends lives
     * @param  CustomControllerLookup|null  $lookup  how a class name becomes a path;
     *                                               the running application's
     *                                               autoloader by default
     */
    public function __construct(
        private string $namespace,
        ?CustomControllerLookup $lookup = null,
    ) {
        $this->lookup = $lookup ?? CustomControllerLookup::fromAutoloader();
    }

    /**
     * @param  list<Operation>  $operations
     * @return list<PlannedScaffold>
     */
    public function plan(array $operations): array
    {
        $planned = [];

        foreach ($operations as $operation) {
            if ($operation->controller === null) {
                continue;
            }

            $path = $this->lookup->pathFor($operation->controller);

            if ($path === null) {
                continue;
            }

            $name = ControllerName::for($operation);

            $planned[] = new PlannedScaffold(
                $operation,
                $operation->controller,
                $path,
                $this->namespace.'\\Controllers\\'.$name->shortName,
                // The same question `pathFor()` above answers from a single
                // candidate is asked here of every candidate PSR-4 would accept —
                // because a class already written under the second directory of a
                // prefix that maps two is a class this scaffold would otherwise
                // shadow with a fresh stub in the first.
                $this->lookup->exists($operation->controller),
            );
        }

        return $planned;
    }

    /**
     * The operations a scaffold cannot be planned for, and there is only one
     * reason: the contract has not said their controller is customizable.
     *
     * Returned rather than refused, because in bulk this is ordinary. A contract
     * of two hundred operations where three declare `x-controller` should scaffold
     * three files and say what it did about the rest — not stop at the fourth.
     *
     * @param  list<Operation>  $operations
     * @return list<Operation>
     */
    public function undeclarable(array $operations): array
    {
        return array_values(array_filter(
            $operations,
            static fn (Operation $operation): bool => $operation->controller === null,
        ));
    }

    /**
     * The operations that declare a controller no PSR-4 prefix in this project maps.
     *
     * Collected rather than thrown, for the same reason `undeclarable()` is: a
     * contract of two hundred operations where one `x-controller` has a typo'd
     * namespace should scaffold the rest and say what it did about that one — not
     * stop before listing or asking about any of them.
     *
     * @param  list<Operation>  $operations
     * @return list<Operation>
     */
    public function unplaceable(array $operations): array
    {
        return array_values(array_filter(
            $operations,
            fn (Operation $operation): bool => $operation->controller !== null
                && $this->lookup->pathFor($operation->controller) === null,
        ));
    }
}
