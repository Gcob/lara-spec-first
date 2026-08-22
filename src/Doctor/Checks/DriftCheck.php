<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Generation\BuildPlan;
use Gcob\LaraSpecFirst\Generation\GeneratedTree;

/**
 * Whether the generated tree still matches the specification. The runtime
 * cannot notice that someone edited the spec and forgot to build, so this is
 * the only thing standing between that mistake and production.
 *
 * Shares the `BuildPlan` {@see RoutingOutcomeCheck} already built rather than
 * planning a second time — one `BuildPlanner::plan()` call for both sections.
 *
 * @see docs/guide/doctor.md — "What it checks"
 * @see docs/guide/code-generation.md — "The runtime never sees the spec"
 */
final readonly class DriftCheck
{
    private const SECTION = 'Drift';

    /**
     * @return list<Finding>
     */
    public static function check(?BuildPlan $plan, string $generatedRoot): array
    {
        // Nothing to compare against: Routing outcome already reported why
        // the plan itself could not be built, and that finding is enough.
        if ($plan === null) {
            return [];
        }

        // A generated tree that does not exist at all is a fresh clone that
        // has never been built, the same legitimate state the service
        // provider itself treats as silence rather than a fault — not
        // drift, which is specifically the gap between a spec and a *build
        // that already happened*. Reporting "everything would be created"
        // here would tell every project that has simply never run
        // `spec:build` yet that its generated tree has drifted, which is not
        // what drifted means.
        if (! is_dir($generatedRoot)) {
            return [];
        }

        $diff = (new GeneratedTree($generatedRoot))->diff($plan->files);
        $findings = [];

        foreach ($diff->toWrite as $relative) {
            $findings[] = self::finding(sprintf(
                '%s no longer matches what a build would emit — the generated tree has drifted from the '.
                'specification. Run `php artisan spec:build` to bring it back in sync.',
                $relative,
            ));
        }

        foreach ($diff->toPrune as $relative) {
            $findings[] = self::finding(sprintf(
                '%s is generated but the specification no longer describes the operation it came from — a build '.
                'would prune it. Run `php artisan spec:build` to bring it back in sync.',
                $relative,
            ));
        }

        return $findings;
    }

    private static function finding(string $message): Finding
    {
        // Drift genuinely fits neither of doctor.md's two classes: it is not
        // the document's own fault, and it is not a stance this package
        // takes on an otherwise-correct document either — it is a developer
        // forgetting to run `spec:build` after editing the spec. Filed as a
        // document fault anyway, on purpose, because the two classes exist
        // to decide how hard the exit code gates a pipeline, and drift wants
        // the harder of the two: it is exactly the gap between what the spec
        // now says and what production is actually running, which is what
        // this section exists to catch before a deploy rather than during
        // one. `spec:build` is the fix in every case, named in the message.
        return new Finding(FindingClass::DocumentFault, self::SECTION, null, '', $message);
    }
}
