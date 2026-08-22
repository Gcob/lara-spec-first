<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\DocumentPointer;
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
     * Constructs this PR counts for the Deferred lot, and what each label
     * counts — the query, header and cookie parameters a Path Item or an
     * Operation Object can hold, `requestBody`, and `responses`. Every one of
     * them is `Deferred` in docs/guide/openapi-support.md's own matrix:
     * recognized, support planned, not built.
     *
     * **Each label names the unit it counts, and the count matches it.** The
     * whole content of one of these findings is a number, so a label counting
     * something other than what it says is the entire finding being wrong —
     * `responses` counts response *declarations* across the document, not
     * operations that declare any.
     */
    private const DEFERRED_LABELS = [
        'parameters' => 'non-path parameter declaration(s) (query, header or cookie)',
        'requestBody' => 'request body/bodies',
        'responses' => 'response declaration(s)',
    ];

    /**
     * The verbs a Path Item Object may carry an Operation Object under.
     * Everything else at that level — `parameters`, `summary`, `$ref`,
     * `servers` — belongs to the path rather than to one operation.
     */
    private const VERBS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

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
                    // This section knows the position exactly — the operation
                    // it is reading is right here — so doctor.md's "every
                    // finding names the document position" is answerable in
                    // structure rather than only in the prose of the message.
                    // Built through {@see DocumentPointer} rather than
                    // assembled here, so this pointer and the one the
                    // generated file's source map carries are the same string
                    // for the same operation.
                    DocumentPointer::forOperation($operation),
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
     * **No pointer, and that is a property of the finding rather than a gap.**
     * A count spans every position that contributed to it; naming one of them
     * would point a reader at an arbitrary occurrence and imply the other
     * nineteen are elsewhere. `{@see self::missingOperationId()}` and
     * `RoutingOutcomeCheck`'s shadowing findings each name one position and
     * carry one — see docs/guide/doctor.md for what the report promises per
     * finding.
     *
     * **Every node is read as `mixed` and checked.** This walks the raw
     * document, which means a document the parser already faulted on is still
     * walked: `parameters: oops` is a real thing a person writes, and this
     * section running after Document validity has to survive it and say what
     * it can, not take the process down with a `foreach` over a string. The
     * fault is already in the report by the time this runs.
     *
     * @return list<Finding>
     */
    public static function deferred(?ParsableSpecDocument $document): array
    {
        if ($document === null) {
            return [];
        }

        $raw = $document->raw;

        $counts = [
            'parameters' => self::countDeferredParameters($raw),
            'requestBody' => 0,
            'responses' => 0,
        ];

        foreach (self::operationNodes($raw) as $operation) {
            if (isset($operation['requestBody'])) {
                $counts['requestBody']++;
            }

            $responses = $operation['responses'] ?? null;

            // Declarations, not operations: `/users/{id}` `get` declaring both
            // `200` and `404` is two response declarations, and the label says
            // declarations. Counting operations here is how "8 response
            // declaration(s)" came to be printed for a document holding
            // considerably more than eight.
            if (is_array($responses)) {
                $counts['responses'] += count($responses);
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
     * Every non-path parameter the document declares, from both levels
     * OpenAPI allows one at.
     *
     * **A Path Item's own `parameters` count.** OpenAPI lets a path declare
     * parameters shared by every operation under it, and a specification that
     * puts all its query parameters there — a perfectly ordinary style — used
     * to report zero deferred parameters, which is rule 2 defeated for a
     * construct this package genuinely does not honor yet. Counted once per
     * path rather than once per operation beneath it, because one declaration
     * is what is written and what a reader would count.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private static function countDeferredParameters(array $raw): int
    {
        $count = 0;

        foreach (self::pathItems($raw) as $pathItem) {
            foreach (self::parameterNodes($pathItem) as $parameter) {
                if (self::isDeferredParameter($parameter, $raw)) {
                    $count++;
                }
            }

            foreach (self::VERBS as $verb) {
                if (is_array($pathItem[$verb] ?? null)) {
                    foreach (self::parameterNodes($pathItem[$verb]) as $parameter) {
                        if (self::isDeferredParameter($parameter, $raw)) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * The entries of one node's `parameters`, or nothing at all when it does
     * not hold a list.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<mixed>
     */
    private static function parameterNodes(array $node): array
    {
        $parameters = $node['parameters'] ?? null;

        return is_array($parameters) ? array_values($parameters) : [];
    }

    /**
     * Whether one entry of a `parameters` list is a parameter this package
     * defers: declared, recognized, and not a path parameter — path
     * parameters are the one kind the build already honors.
     *
     * **A `$ref`'d parameter is resolved, not assumed.** `{$ref:
     * '#/components/parameters/UserId'}` carries no `in` of its own, so
     * reading `in` off the reference counted a referenced *path* parameter
     * toward the non-path total. Resolved against the same document, which is
     * where a local pointer points; a reference this method cannot follow —
     * into another file, or at a position the document does not hold — is
     * skipped rather than guessed at, on the same rule the rest of this class
     * follows: a number that might be wrong is worse than a number that is
     * short by a case it says it cannot see.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private static function isDeferredParameter(mixed $parameter, array $raw): bool
    {
        if (! is_array($parameter)) {
            return false;
        }

        $reference = $parameter['$ref'] ?? null;

        if (is_string($reference)) {
            $resolved = self::resolveLocalPointer($raw, $reference);

            if ($resolved === null) {
                return false;
            }

            $parameter = $resolved;
        }

        $in = $parameter['in'] ?? null;

        // Present *and* not `path`, rather than "not `path`": a Parameter
        // Object with no `in` at all is not a parameter this package could
        // defer, it is a document the parser will refuse, and counting it
        // would put a wrong number on the one line whose whole content is a
        // number.
        return is_string($in) && $in !== 'path';
    }

    /**
     * The node a `#/...` JSON Pointer names inside this same document, or
     * null for anything this method cannot follow — a reference into another
     * file, a pointer at a position the document does not hold, or a target
     * that is not a node.
     *
     * Deliberately not a general `$ref` resolver: `Parsing\` owns resolution,
     * and this is one lookup a count needs rather than a second resolver for
     * the package to keep in step with the first.
     *
     * @param  array<array-key, mixed>  $raw
     * @return ?array<array-key, mixed>
     */
    private static function resolveLocalPointer(array $raw, string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $node = $raw;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! array_key_exists($key, $node)) {
                return null;
            }

            /** @var mixed $next */
            $next = $node[$key];

            if (! is_array($next)) {
                return null;
            }

            $node = $next;
        }

        return $node;
    }

    /**
     * Every Path Item Object in the document, or nothing when `paths` is not
     * a map of them.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<array<array-key, mixed>>
     */
    private static function pathItems(array $raw): array
    {
        $paths = $raw['paths'] ?? null;

        if (! is_array($paths)) {
            return [];
        }

        $items = [];

        foreach ($paths as $pathItem) {
            if (is_array($pathItem)) {
                $items[] = $pathItem;
            }
        }

        return $items;
    }

    /**
     * Every Operation Object in the document, read straight off the raw
     * array rather than through `Contract\Operation` — the extraction
     * pipeline does not carry `parameters`, `requestBody` or `responses`
     * forward at all today, so counting them has nothing typed to count.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<array<array-key, mixed>>
     */
    private static function operationNodes(array $raw): array
    {
        $operations = [];

        foreach (self::pathItems($raw) as $pathItem) {
            foreach (self::VERBS as $verb) {
                if (is_array($pathItem[$verb] ?? null)) {
                    $operations[] = $pathItem[$verb];
                }
            }
        }

        return $operations;
    }
}
