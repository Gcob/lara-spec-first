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
 * @see docs/guide/code-generation.md — "The build command: spec:build"
 */
final readonly class BuildPlanner
{
    public function __construct(
        private string $namespace,
        private string $specPath,
    ) {}

    /**
     * @param  list<Operation>  $operations  in the order the document writes them
     * @return list<GeneratedFile>
     *
     * @throws UnusableNameException a name PHP cannot carry, or two operations claiming one
     * @throws UnroutablePathException a path parameter Laravel's router cannot match
     */
    public function plan(array $operations): array
    {
        $planned = [];
        $claimed = [];

        foreach ($operations as $operation) {
            $this->assertRoutable($operation);

            $name = ControllerName::for($operation);
            $label = $operation->label();

            if (isset($claimed[$name->shortName])) {
                throw UnusableNameException::claimedTwice(
                    $name->shortName,
                    $claimed[$name->shortName],
                    $label,
                );
            }

            $claimed[$name->shortName] = $label;
            $planned[] = new PlannedController($operation, $name);
        }

        $controllers = new ControllerEmitter($this->namespace, $this->specPath);
        $files = array_map(
            static fn (PlannedController $controller): GeneratedFile => $controllers->emit($controller),
            $planned,
        );

        $files[] = (new RoutesEmitter($this->namespace, $this->specPath))->emit($planned);

        return $files;
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
        }
    }
}
