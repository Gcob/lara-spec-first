<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Doctor\FaultClassification;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * Is this valid OpenAPI? The parser does not answer this in its own library
 * API — see docs/guide/openapi-support.md#parser-caveats — so the doctor owns
 * it, by reading what the reading pipeline already collected rather than
 * validating anything itself a second time.
 *
 * @see docs/guide/doctor.md — "What it checks"
 */
final readonly class DocumentValidityCheck
{
    /**
     * @return list<Finding>
     */
    public static function check(ReadOutcome $outcome): array
    {
        $findings = [];

        foreach ($outcome->faults as $fault) {
            if (FaultClassification::sectionOf($fault) === 'Document validity') {
                $findings[] = FaultClassification::fromFault($fault);
            }
        }

        return $findings;
    }
}
