<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Doctor\FaultClassification;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\ParsableSpecDocument;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * Every `Partial`, `Ignored` and `Rejected` construct in the document, with
 * its position — the widest section in this PR, so it is built in the four
 * lots docs/project/roadmap.md and the planning document for this PR
 * describe, rather than the whole support matrix at once:
 *
 *   1. **Rejected, already detected by the reading pipeline.** `trace`, a
 *      `$ref` into `components.pathItems`, a `$dynamicRef`/`$dynamicAnchor`.
 *      Already collected as faults tagged for this section by
 *      {@see FaultClassification::sectionOf()} — nothing left to detect,
 *      only to surface. See {@see self::rejected()}.
 *   2. **Partial, already read by `OperationExtractor`.** Only the one rule
 *      this PR adds new logic for: `operationId` is required on an operation
 *      that is both `public` and `stable`. See {@see self::missingOperationId()}.
 *   3. **Deferred, one line per construct rather than per occurrence** — a
 *      count, not a list, because the level's own definition forbids one
 *      finding per occurrence. See {@see self::deferred()}.
 *   4. **`Open` constructs are out of scope for this PR** — the package has
 *      taken no position on them yet, so there is nothing to honor or refuse
 *      that a finding could point at. Not a gap: a finding a reader cannot
 *      act on is what doctor.md calls a rumor.
 *
 * @see docs/guide/doctor.md — "What it checks"
 * @see docs/guide/openapi-support.md — "The support matrix"
 */
final readonly class SupportMatrixCheck
{
    private const SECTION = 'Support findings';

    /**
     * Constructs this PR counts for the Deferred lot, and the JSON pointer
     * segment each lives under — the query, header and cookie parameters
     * `paths.*.*.parameters` can hold, `requestBody`, and `responses`. Every
     * one of them is `Deferred` in docs/guide/openapi-support.md's own
     * matrix: recognized, support planned, not built.
     */
    private const DEFERRED_LABELS = [
        'parameters' => 'non-path parameter(s) (query, header or cookie)',
        'requestBody' => 'request body/bodies',
        'responses' => 'response declaration(s)',
    ];

    /**
     * @return list<Finding>
     */
    public static function rejected(ReadOutcome $outcome): array
    {
        $findings = [];

        foreach ($outcome->faults as $fault) {
            if (FaultClassification::sectionOf($fault) === self::SECTION) {
                $findings[] = FaultClassification::fromFault($fault);
            }
        }

        return $findings;
    }

    /**
     * `operationId` is `Partial`: honored when present, and required once an
     * operation promises `public` and `stable` at once — elsewhere the
     * method and path stand in for it. Nothing in the reading pipeline
     * refuses its absence today, so this is genuinely new logic rather than
     * a translated fault, unlike {@see self::rejected()}.
     *
     * @param  list<Operation>  $operations
     * @return list<Finding>
     */
    public static function missingOperationId(array $operations): array
    {
        $findings = [];

        foreach ($operations as $operation) {
            if (
                $operation->operationId === null
                && $operation->audience === Audience::Public
                && $operation->lifecycle === Lifecycle::Stable
            ) {
                $findings[] = new Finding(
                    FindingClass::DocumentFault,
                    self::SECTION,
                    SupportLevel::Partial,
                    '',
                    sprintf(
                        '%s promises public and stable but declares no operationId. That promise is the one '.
                        'case this package requires one for — elsewhere the method and path stand in for it.',
                        $operation->label(),
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * One line per Deferred construct actually present, naming how many —
     * never one per occurrence, which the level's own definition forbids:
     * "Deferred exists because the exit code has to stay reachable" only
     * holds if a whole document's worth of them still reads as one line.
     *
     * @return list<Finding>
     */
    public static function deferred(?ParsableSpecDocument $document): array
    {
        if ($document === null) {
            return [];
        }

        $counts = ['parameters' => 0, 'requestBody' => 0, 'responses' => 0];

        foreach (self::operationNodes($document->raw) as $operation) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if (is_array($parameter) && ($parameter['in'] ?? null) !== 'path') {
                    $counts['parameters']++;
                }
            }

            if (isset($operation['requestBody'])) {
                $counts['requestBody']++;
            }

            if (isset($operation['responses']) && is_array($operation['responses']) && $operation['responses'] !== []) {
                $counts['responses']++;
            }
        }

        $findings = [];

        foreach ($counts as $construct => $count) {
            if ($count === 0) {
                continue;
            }

            $findings[] = new Finding(
                FindingClass::PackageLimit,
                self::SECTION,
                SupportLevel::Deferred,
                '',
                sprintf(
                    '%d %s found. Recognized, support planned, not built yet — see docs/project/roadmap.md. '.
                    'This is a fact about this package\'s roadmap, not a defect in your contract.',
                    $count,
                    self::DEFERRED_LABELS[$construct],
                ),
            );
        }

        return $findings;
    }

    /**
     * Every Operation Object in the document, read straight off the raw
     * array rather than through `Contract\Operation` — the extraction
     * pipeline does not carry `parameters`, `requestBody` or `responses`
     * forward at all today, so counting them has nothing typed to count.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function operationNodes(array $raw): array
    {
        $paths = $raw['paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $verbs = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];
        $operations = [];

        foreach ($paths as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach ($verbs as $verb) {
                if (isset($pathItem[$verb]) && is_array($pathItem[$verb])) {
                    $operations[] = $pathItem[$verb];
                }
            }
        }

        return $operations;
    }
}
