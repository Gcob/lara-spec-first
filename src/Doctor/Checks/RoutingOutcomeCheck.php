<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
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
 * shadowing, where an earlier templated path swallows a later one.
 *
 * **Shadowing covers a later template, not only a later literal path**, and
 * the templated case is the worse of the two: `GET /{owner}/{repo}` matches
 * every two-segment GET, so a `GET /users/{id}` written after it is a whole
 * resource that will never be reached, not one endpoint. The check used to
 * skip any later operation whose own path was templated, which left exactly
 * the shape real specifications make this mistake in reported as clean.
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
     * The single path segment a later template's parameters are probed with.
     *
     * Named rather than inlined because its one requirement is worth stating:
     * it must be a segment no specification would write as a literal, since a
     * literal segment in the earlier template matches only a request segment
     * equal to it.
     */
    private const PROBE_SEGMENT = 'lsf-probe';

    /**
     * @param  list<Operation>  $operations
     */
    public static function check(array $operations, string $namespace, string $specPath): RoutingOutcomeResult
    {
        // Trimmed once and used everywhere below, including in the route
        // target this section prints: a `generated.namespace` written with a
        // trailing backslash used to reach `routeTarget()` untrimmed and print
        // `App\Http\Generated\\Controllers\ShowUserController` in the table.
        $namespace = rtrim($namespace, '\\');

        try {
            $plan = (new BuildPlanner($namespace, ProjectRelativePath::from($specPath)))
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
     * An earlier templated path that would match every request a later one
     * was meant to answer — the later operation would never be reached,
     * because Laravel keeps the document's own order and the first match wins.
     *
     * **The earlier route is compiled once per candidate, not once per pair.**
     * `new Route(...)` inside the inner loop turned an n-operation document
     * into up to n²/2 compilations; hoisting it makes that n, and makes this
     * method shorter rather than longer.
     *
     * **What this does and does not catch.** A later path is tested by
     * substituting one sample segment for each of its own parameters, so a
     * template fully swallowed by an earlier one is found. A *partial* overlap
     * is not, and that is deliberate rather than missing: `GET /users/{id}`
     * written before `GET /{a}/{b}` shadows the second only for requests whose
     * first segment is `users`, and reporting a route that is reachable for
     * every other value as "will never be reached" would be false. Being
     * unreachable for some inputs is a different finding, and one this section
     * does not make.
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

            $verb = strtoupper($earlier->method->value);
            $earlierRoute = new Route([$verb], $earlier->path->template, static fn (): null => null);

            foreach ($controllers as $j => $laterController) {
                if ($j <= $i) {
                    continue;
                }

                $later = $laterController->operation;

                if ($later->method !== $earlier->method) {
                    continue;
                }

                if (! self::shadows($earlierRoute, $verb, $later->path->template)) {
                    continue;
                }

                $findings[] = new Finding(
                    FindingClass::DocumentFault,
                    self::SECTION,
                    null,
                    // The operation that loses, not the one that wins: the
                    // finding is about a route that will never be reached, so
                    // the position a reader needs is the one they have to move
                    // or rename. This section knows both exactly, which is why
                    // it carries a pointer where a translated fault cannot.
                    DocumentPointer::forOperation($later),
                    sprintf(
                        '`%s %s` is registered before `%s %s` and matches every request meant for the second '.
                        'one — Laravel keeps the document\'s own order and the first match wins, so `%3$s %4$s` '.
                        'will never be reached.',
                        $verb,
                        $earlier->path->template,
                        strtoupper($later->method->value),
                        $later->path->template,
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * Whether the already-compiled earlier route would match a request for the
     * later path — the real Symfony route compiler Laravel itself uses, rather
     * than a regex reinvented for this one check.
     *
     * **A later template is probed with a sample segment per parameter.** A
     * route pattern is not a request, and the compiler only answers about
     * requests, so `/{owner}/{repo}` is asked about `/lsf-probe/lsf-probe`
     * instead. The token only has to be a legal single segment: Laravel's
     * default parameter pattern accepts anything without a `/`, and a literal
     * segment in the earlier template can only match a request segment equal to
     * it — which this one is not.
     */
    private static function shadows(Route $earlierRoute, string $verb, string $laterTemplate): bool
    {
        // Uppercased even though `matches(..., false)` never compares the
        // method at all — verified directly against Symfony's compiler,
        // lowercase and mixed case both match identically here — so this is
        // for a future reader who has not verified that rather than for
        // correctness: `HttpMethod::$value` is lowercase, and every method
        // string elsewhere in this codebase is written the conventional way.
        $request = Request::create(self::probePath($laterTemplate), $verb);

        return $earlierRoute->matches($request, false);
    }

    /**
     * One concrete request path a route of this template would serve.
     *
     * Every `{name}` — optional `{name?}` included, since an omitted optional
     * segment is a shorter path the earlier route would be asked about
     * separately — becomes the same probe token.
     */
    private static function probePath(string $template): string
    {
        return (string) preg_replace('/\{[^}]*\}/', self::PROBE_SEGMENT, $template);
    }
}
