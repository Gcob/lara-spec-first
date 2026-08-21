<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

use Gcob\LaraSpecFirst\Parsing\Version\SpecVersion;

/**
 * Everything `spec:doctor` found, in one place — the outcome, not only the
 * problems: doctor.md is explicit that the resolved routing table is as much
 * the point as any finding, so this carries both rather than only the latter.
 *
 * @see docs/guide/doctor.md — "The contract"
 */
final readonly class DiagnosticReport
{
    /**
     * @param  list<string>  $allowedHosts  the effective `remote_references.allowed_hosts`
     * @param  list<RouteOutcome>  $routes  every route the build would register, in document order
     * @param  list<Finding>  $findings  every finding from every section, in the order sections run
     */
    public function __construct(
        public string $specPath,
        public bool $specFileFound,
        public array $allowedHosts,
        public string $vendorPath,
        public ?SpecVersion $version,
        public array $routes,
        public array $findings,
    ) {}

    /**
     * Clean means the exit code is zero — see {@see self::exitCode()} for
     * exactly what that excludes. A report carrying only `Deferred` findings
     * is clean by this definition, even though those findings still print:
     * `Deferred` is a fact about this package's roadmap, stated in
     * docs/guide/openapi-support.md to never fail a pipeline over it.
     */
    public function isClean(): bool
    {
        return $this->exitCode() === 0;
    }

    public function hasDocumentFault(): bool
    {
        return self::hasGatingFinding($this->findings, FindingClass::DocumentFault);
    }

    public function hasPackageLimit(): bool
    {
        return self::hasGatingFinding($this->findings, FindingClass::PackageLimit);
    }

    /**
     * `0` clean. `1` at least one document fault — a CI pipeline gates hard on
     * this. `2` no document fault, but at least one package limit — a softer
     * signal. A document fault always wins when both are present: it is the
     * one a report cannot let a package limit's noisier count bury.
     *
     * The numbers themselves are provisional — see doctor.md's own open
     * question on them — but the ordering they express is not.
     *
     * @see docs/guide/doctor.md — "Two kinds of finding, never mixed"
     */
    public function exitCode(): int
    {
        return match (true) {
            $this->hasDocumentFault() => 1,
            $this->hasPackageLimit() => 2,
            default => 0,
        };
    }

    /**
     * A finding of this class counts toward the exit code unless it is
     * `Deferred` — docs/guide/openapi-support.md's support-levels table marks
     * `Deferred`'s own exit-code column "—", the same as `Supported` and
     * `Out of scope`: recognizing a construct the package has not built yet
     * is a fact about the roadmap, not a reason to fail a pipeline. Every
     * other level a `Finding` can carry — `Partial`, `Ignored`, `Rejected`,
     * or none at all (a document fault has no support level to begin with) —
     * gates normally.
     *
     * @param  list<Finding>  $findings
     */
    private static function hasGatingFinding(array $findings, FindingClass $class): bool
    {
        foreach ($findings as $finding) {
            if ($finding->class === $class && $finding->level !== SupportLevel::Deferred) {
                return true;
            }
        }

        return false;
    }
}
