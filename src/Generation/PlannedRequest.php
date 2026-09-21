<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;

/**
 * An operation paired with the request class that will validate it.
 *
 * The rule set is built once, here, because two readers need it: the emitter
 * writes it into `rules()`, and the command counts what the build could not
 * translate. Building it twice would be two chances to build it differently.
 *
 * **The controller's short name travels with it** so the generated request can
 * point at the `routeAction` that declares it. Without that type hint nothing
 * validates anything, so the one thing a reader of this file has to be able to
 * check is where it is declared.
 */
final readonly class PlannedRequest
{
    public function __construct(
        public Operation $operation,
        public RequestName $name,
        public RuleSet $rules,
        public string $controllerShortName,
    ) {}

    /**
     * @param  string  $namespace  the configured generated root namespace
     */
    public function fullyQualifiedName(string $namespace): string
    {
        return $this->name->fullyQualifiedName($namespace);
    }

    public function relativePath(): string
    {
        return $this->name->relativePath();
    }
}
