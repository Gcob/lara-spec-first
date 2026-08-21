<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;

/**
 * An operation paired with the name its generated controller will take.
 *
 * Exists because both the controller file and the routes file need that pairing,
 * and deriving the name twice would be two chances to derive it differently.
 */
final readonly class PlannedController
{
    public function __construct(
        public Operation $operation,
        public ControllerName $name,
    ) {}

    /**
     * @param  string  $namespace  the configured generated root namespace
     */
    public function fullyQualifiedName(string $namespace): string
    {
        return $namespace.'\\Controllers\\'.$this->name->shortName;
    }

    public function relativePath(): string
    {
        return 'Controllers/'.$this->name->shortName.'.php';
    }
}
