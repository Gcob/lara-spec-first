<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Doctor\FaultClassification;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * Unresolved `$ref`, references blocked by the allowlist, and `$ref` cycles —
 * each already its own entry in `ReadOutcome::$faults` rather than the first
 * one found having stopped the rest. See
 * docs/guide/openapi-support.md#the-pipeline-does-not-throw-callers-decide.
 *
 * @see docs/guide/doctor.md — "What it checks"
 */
final readonly class ReferencesCheck
{
    /**
     * @return list<Finding>
     */
    public static function check(ReadOutcome $outcome): array
    {
        $findings = [];

        foreach ($outcome->faults as $fault) {
            if (FaultClassification::sectionOf($fault) === 'References') {
                $findings[] = FaultClassification::fromFault($fault);
            }
        }

        return $findings;
    }
}
