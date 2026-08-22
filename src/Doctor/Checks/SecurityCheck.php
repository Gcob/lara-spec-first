<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Parsing\ParsableSpecDocument;

/**
 * The one finding that turns a documented-as-protected endpoint into a public
 * one: this phase registers routes without applying `security` at all, so an
 * operation whose contract requires a scheme is served with no authorization
 * check behind it.
 *
 * **Every affected operation is listed individually, on every run, and never
 * folded into a count.** That is the treatment
 * docs/guide/doctor.md#acknowledging-changes-behavior-not-just-noise already
 * reserves for security, applied here rather than invented: summarizing "12
 * operations declare security" would hide exactly the list a reader needs to
 * go and protect by hand in the meantime. It is also why this is a section of
 * its own rather than a line among the Support findings.
 *
 * Deleted in the same change that makes `security` an authorization check —
 * see docs/project/roadmap.md, "Authorization the contract can express".
 *
 * @see docs/guide/doctor.md — "What it checks"
 * @see docs/guide/security.md
 */
final readonly class SecurityCheck
{
    private const SECTION = 'Security';

    /**
     * @param  list<Operation>  $operations
     * @return list<Finding>
     */
    public static function check(array $operations): array
    {
        $findings = [];

        foreach ($operations as $operation) {
            // Three states, and only one of them is reported: a list of
            // requirements. `null` is an operation that says nothing and
            // inherits the document's block, which this package does not read
            // at all {@see self::inheritsUnreadRootRequirements()}, and an
            // empty list is an operation explicitly requiring nothing —
            // exactly what this phase already delivers, so there is nothing
            // to warn about.
            if ($operation->security === null || $operation->security === []) {
                continue;
            }

            $findings[] = new Finding(
                // A package limit, never a document fault: the contract is
                // correct and complete. We are the ones not honoring it yet.
                FindingClass::PackageLimit,
                self::SECTION,
                // `Partial` rather than `Deferred`, and the difference is the
                // whole point: `Deferred` never gates the exit code, so a
                // pipeline would go green over an endpoint documented as
                // protected and served unprotected. `Partial` is also what
                // docs/guide/openapi-support.md's own matrix already says of
                // `security` — read, its three states preserved, nothing
                // applied.
                SupportLevel::Partial,
                '',
                sprintf(
                    '%s requires %s. This package reads that requirement and does not apply it yet: the route is '.
                    'registered with no authorization check behind it, so an operation your contract documents as '.
                    'protected is served as public. Enforcement is Phase 2 — see docs/guide/security.md.',
                    $operation->label(),
                    self::requirements($operation->security),
                ),
            );
        }

        return $findings;
    }

    /**
     * Whether some operation actually inherits a root `security` block this
     * package does not read.
     *
     * Reported as one informational line rather than as a finding per
     * inheriting operation, because the package does not read the block:
     * `security` (root) is `Open` in
     * docs/guide/openapi-support.md#the-support-matrix, so what an operation
     * that stays silent actually inherits is not carried in `Contract\` at
     * all. Naming the block is what this check can say honestly; listing the
     * operations under it would mean claiming to know a requirement nothing
     * here resolved.
     *
     * **Both halves are checked, because the line asserts both.** It used to
     * ask only whether the block existed, and then print that "an operation
     * that states no security of its own inherits requirements nothing here
     * resolved" — a sentence with no referent on a document that declares a
     * root block and overrides it on every single operation, which is a
     * perfectly ordinary way to write one. The consequence is the half a
     * reader would act on, so it is only stated when there is an operation it
     * is true of. Where every operation states its own requirements the block
     * changes nothing this package does, and silence is the honest answer
     * rather than a caveat about a risk that does not exist.
     *
     * @param  list<Operation>  $operations
     */
    public static function inheritsUnreadRootRequirements(?ParsableSpecDocument $document, array $operations): bool
    {
        $root = $document?->raw['security'] ?? null;

        if (! is_array($root) || $root === []) {
            return false;
        }

        foreach ($operations as $operation) {
            // Null, not `[]`: an operation stating an empty list requires
            // nothing *explicitly* and inherits nothing — the middle of the
            // three states {@see self::check()} reasons about.
            if ($operation->security === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The requirements as a reader can act on them: alternatives joined by
     * "or", the schemes inside one alternative joined by "and", each with the
     * scopes it asks for.
     *
     * **Nothing is sorted here, and nothing needs to be.** The schemes inside
     * one requirement are ANDed, so their order carries no meaning, and
     * `SecurityRequirement` settles it once at construction — `fromSchemes()`
     * sorts by scheme name and is the only way to build one, the constructor
     * being private. Sorting a second time here would be a second answer to a
     * question already answered, and the day the two disagreed the message
     * would depend on which one ran last. The order of the alternatives, and
     * of the scopes inside one scheme, is the document's own and is left
     * alone: both are the author's, and reordering either would make the
     * message harder to check against the file it describes.
     *
     * @param  list<SecurityRequirement>  $requirements
     */
    private static function requirements(array $requirements): string
    {
        $alternatives = [];

        foreach ($requirements as $requirement) {
            $schemes = [];

            foreach ($requirement->schemes as $name => $scopes) {
                $schemes[] = $scopes === [] ? $name : sprintf('%s (%s)', $name, implode(', ', $scopes));
            }

            $alternatives[] = implode(' and ', $schemes);
        }

        return implode(' or ', $alternatives);
    }
}
