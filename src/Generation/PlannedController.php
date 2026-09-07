<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;

/**
 * An operation paired with the name its generated controller will take.
 *
 * Exists because both the controller file and the routes file need that pairing,
 * and deriving the name twice would be two chances to derive it differently. The
 * same now goes for the third fact here: whether the custom controller the
 * contract names has been written yet. The controller's docblock and the route's
 * target both depend on it, and asking the filesystem twice would let one file
 * say what the other contradicts.
 */
final readonly class PlannedController
{
    /**
     * @param  bool  $customControllerExists  whether a file for {@see ControllerName::$customController}
     *                                        can be found, resolved once per build
     */
    public function __construct(
        public Operation $operation,
        public ControllerName $name,
        public bool $customControllerExists = false,
    ) {}

    /**
     * @param  string  $namespace  the configured generated root namespace
     */
    public function fullyQualifiedName(string $namespace): string
    {
        return $namespace.'\\Controllers\\'.$this->name->shortName;
    }

    /**
     * The class the route registers, which is the child when there is one.
     *
     * **Resolved here, at build time, rather than by the router.** The
     * registration has to stay a pair of plain strings for `route:cache`, so the
     * choice cannot be deferred to a request — and there is nothing to defer: the
     * specification named the child, and either its file is there or it is not.
     *
     * The generated parent answers until then, which is what keeps an operation
     * whose custom controller has not been written yet at
     * [501 rather than a fatal](../../docs/guide/code-generation/index.md).
     */
    public function routeTarget(string $namespace): string
    {
        $custom = $this->name->customController;

        return $custom !== null && $this->customControllerExists
            ? $custom
            : $this->fullyQualifiedName($namespace);
    }

    /**
     * Whether the route points at a class this package did not write.
     */
    public function routesToCustomController(): bool
    {
        return $this->name->customController !== null && $this->customControllerExists;
    }

    public function relativePath(): string
    {
        return 'Controllers/'.$this->name->shortName.'.php';
    }
}
