<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

use Gcob\LaraSpecFirst\Generation\BuildPlan;

/**
 * What {@see Checks\RoutingOutcomeCheck} found: the plan `spec:build` would
 * write from — shared with {@see Checks\DriftCheck} so the two never build it
 * twice and never risk disagreeing about what it says — the resolved routes,
 * and any finding along the way.
 */
final readonly class RoutingOutcomeResult
{
    /**
     * @param  ?BuildPlan  $plan  null when the plan itself could not be built —
     *                            the fault that stopped it is in `$findings`,
     *                            and `Checks\DriftCheck` has nothing to compare
     *                            against either
     * @param  list<RouteOutcome>  $routes
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public ?BuildPlan $plan,
        public array $routes,
        public array $findings,
    ) {}
}
