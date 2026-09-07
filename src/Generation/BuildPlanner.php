<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnroutablePathException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

/**
 * Works out every file the build will write, before any of it is written.
 *
 * **The separation is the point, not a structure for its own sake.** A build that
 * emitted as it went would leave a half-generated tree behind the first operation
 * it could not handle, and half-generated output from a broken contract is worse
 * than no output: it analyses, it autocompletes, and it lies. Planning everything
 * first means a refusal costs nothing — the working tree is exactly as it was.
 *
 * It also makes the whole of the build's decision-making testable without a
 * filesystem, which is where the interesting parts are: names, collisions, and
 * the paths Laravel cannot route.
 *
 * @see docs/guide/code-generation/index.md — "The build command: spec:build"
 */
final readonly class BuildPlanner
{
    private CustomControllerLookup $lookup;

    /**
     * @param  CustomControllerLookup|null  $lookup  how the plan learns whether a
     *                                               custom controller has been
     *                                               written yet; the running
     *                                               application's autoloader by
     *                                               default, and injectable so a
     *                                               test can state the answer
     *                                               instead of arranging a file
     */
    public function __construct(
        private string $namespace,
        private string $specPath,
        ?CustomControllerLookup $lookup = null,
    ) {
        $this->lookup = $lookup ?? CustomControllerLookup::fromAutoloader();
    }

    /**
     * @param  list<Operation>  $operations  in the order the document writes them
     *
     * @throws UnusableNameException a name PHP cannot carry, or two operations claiming one
     * @throws UnroutablePathException a path parameter Laravel's router cannot match
     */
    public function plan(array $operations): BuildPlan
    {
        $planned = [];
        $claimed = [];

        foreach ($operations as $operation) {
            $this->assertRoutable($operation);

            $name = ControllerName::for($operation);
            $label = $operation->label();

            $this->assertOutsideGeneratedTree($name, $label);

            if (isset($claimed[$name->shortName])) {
                [$firstLabel, $firstCustom] = $claimed[$name->shortName];

                // Two declared controllers reducing to one generated parent get
                // their own message, because the fix is a different one: distinct
                // fully-qualified names can share a short name — `…\Admin\UserController`
                // and `…\Api\UserController` — and the advice to change an
                // `operationId` would send a reader looking for a key neither
                // operation has.
                throw $firstCustom !== null && $name->customController !== null
                    ? UnusableNameException::parentClaimedTwice(
                        $name->shortName,
                        $firstCustom,
                        $name->customController,
                    )
                    : UnusableNameException::claimedTwice($name->shortName, $firstLabel, $label);
            }

            $claimed[$name->shortName] = [$label, $name->customController];
            $planned[] = new PlannedController(
                $operation,
                $name,
                $name->customController !== null && $this->lookup->exists($name->customController),
            );
        }

        $controllers = new ControllerEmitter($this->namespace, $this->specPath);
        $files = array_map(
            static fn (PlannedController $controller): GeneratedFile => $controllers->emit($controller),
            $planned,
        );

        $files[] = (new RoutesEmitter($this->namespace, $this->specPath))->emit($planned);

        return new BuildPlan($planned, $files);
    }

    /**
     * Refuse a custom controller that would live inside the generated tree.
     *
     * Two things go wrong at once, and either would be enough. The generated
     * parent takes the same short name inside the generated namespace, so a child
     * declared there *is* its own parent — a class extending itself, which PHP
     * refuses at load. And a project's own classes belong outside a directory
     * whose entire contract is that a build rewrites it and a `.gitignore` may
     * discard it: putting work there is how a consumer loses their work.
     *
     * @throws UnusableNameException
     *
     * @see docs/guide/code-generation/index.md — "Where your classes go"
     */
    private function assertOutsideGeneratedTree(ControllerName $name, string $label): void
    {
        $custom = $name->customController;

        if ($custom !== null && str_starts_with($custom.'\\', $this->namespace.'\\')) {
            throw UnusableNameException::customControllerInsideGeneratedTree($label, $custom, $this->namespace);
        }
    }

    /**
     * Refuse a path Laravel would accept and never match.
     *
     * Both checks exist because the framework fails silently or fails late. An
     * unsupported character makes the placeholder literal text, so the endpoint
     * 404s forever with nothing reporting it. An over-long name throws from the
     * route compiler, which runs while the application boots — so the failure
     * arrives far from the specification that caused it.
     *
     * @throws UnroutablePathException
     */
    private function assertRoutable(Operation $operation): void
    {
        foreach ($operation->path->parameterNames as $parameter) {
            if (preg_match('/^\w+$/', $parameter) !== 1) {
                throw UnroutablePathException::unsupportedCharacters($operation->label(), $parameter);
            }

            if (strlen($parameter) > UnroutablePathException::PARAMETER_NAME_LIMIT) {
                throw UnroutablePathException::nameTooLong($operation->label(), $parameter);
            }

            // Routable and still unusable, which is why this is a second check
            // rather than a stricter first one. `\w` accepts a leading digit and
            // Symfony's compiler matches `{2fa}` happily; `$2fa` is not a
            // variable, and the generated `routeAction` has to declare one
            // parameter per path parameter for a custom controller to be able to
            // override it. `$this` is the same problem with a different cause:
            // legal in a route, fatal as a parameter name.
            if (preg_match('/^[A-Za-z_]\w*$/', $parameter) !== 1 || $parameter === 'this') {
                throw UnusableNameException::parameterNotAVariable($operation->label(), $parameter);
            }
        }
    }
}
