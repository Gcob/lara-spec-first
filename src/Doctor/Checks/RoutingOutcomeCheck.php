<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\RouteOutcome;
use Gcob\LaraSpecFirst\Doctor\RoutingOutcomeResult;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\PlannedController;
use Gcob\LaraSpecFirst\Generation\ProjectRelativePath;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The routes that will exist, in order, mapped to their controller — plus
 * shadowing, where an earlier templated path swallows a later literal one.
 *
 * **Never writes.** It plans exactly as `spec:build` does —
 * {@see BuildPlanner::plan()}, the same class the build itself calls — and
 * stops there; {@see GeneratedTree::write()} is never reached from here or
 * from anywhere else in this namespace.
 *
 * @see docs/guide/doctor.md — "What it checks"
 */
final readonly class RoutingOutcomeCheck
{
    private const SECTION = 'Routing outcome';

    /**
     * @param  list<Operation>  $operations
     */
    public static function check(array $operations, string $namespace, string $specPath): RoutingOutcomeResult
    {
        try {
            $plan = (new BuildPlanner(rtrim($namespace, '\\'), ProjectRelativePath::from($specPath)))
                ->plan($operations);
        } catch (SpecException $fault) {
            // Not a construct the document describes wrongly, and not a
            // support level this package publishes an opinion on either: a
            // name collision or an unroutable path parameter is this
            // package's own naming scheme, or Laravel's own router, running
            // into an otherwise valid document — a stance this package
            // takes, which is what a package limit is.
            return new RoutingOutcomeResult(null, [], [
                new Finding(FindingClass::PackageLimit, self::SECTION, null, '', $fault->getMessage()),
            ]);
        }

        $routes = array_map(
            static fn (PlannedController $controller): RouteOutcome => new RouteOutcome(
                strtoupper($controller->operation->method->value),
                $controller->operation->path->template,
                $controller->routeTarget($namespace),
                $controller->routesToCustomController(),
            ),
            $plan->controllers,
        );

        return new RoutingOutcomeResult($plan, $routes, self::shadowing($plan->controllers));
    }

    /**
     * An earlier templated path that would match every request a later
     * literal one was meant to answer — the later operation would never be
     * reached, because Laravel keeps the document's own order and the first
     * match wins.
     *
     * @param  list<PlannedController>  $controllers  in document order, exactly
     *                                                as the build would emit routes
     * @return list<Finding>
     */
    private static function shadowing(array $controllers): array
    {
        $findings = [];

        foreach ($controllers as $i => $earlierController) {
            $earlier = $earlierController->operation;

            if ($earlier->path->parameterNames === []) {
                continue;
            }

            foreach ($controllers as $j => $laterController) {
                if ($j <= $i) {
                    continue;
                }

                $later = $laterController->operation;

                if ($later->method !== $earlier->method || $later->path->parameterNames !== []) {
                    continue;
                }

                if (self::shadows($earlier->method, $earlier->path->template, $later->path->template)) {
                    $findings[] = new Finding(
                        FindingClass::DocumentFault,
                        self::SECTION,
                        null,
                        '',
                        sprintf(
                            '`%s %s` is registered before `%s %s` and matches every request meant for the second '.
                            'one — Laravel keeps the document\'s own order and the first match wins, so `%3$s %4$s` '.
                            'will never be reached.',
                            strtoupper($earlier->method->value),
                            $earlier->path->template,
                            strtoupper($later->method->value),
                            $later->path->template,
                        ),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Whether a route compiled from `$earlierTemplate` would match a request
     * for `$laterLiteralPath` — the real Symfony route compiler Laravel
     * itself uses, rather than a regex reinvented for this one check.
     */
    private static function shadows(HttpMethod $method, string $earlierTemplate, string $laterLiteralPath): bool
    {
        // Uppercased even though `matches(..., false)` never compares the
        // method at all — verified directly against Symfony's compiler,
        // lowercase and mixed case both match identically here — so this is
        // for a future reader who has not verified that rather than for
        // correctness: `HttpMethod::$value` is lowercase, and every method
        // string elsewhere in this codebase is written the conventional way.
        $verb = strtoupper($method->value);

        $route = new Route([$verb], $earlierTemplate, static fn (): null => null);
        $request = Request::create($laterLiteralPath, $verb);

        return $route->matches($request, false);
    }
}
