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
     * @param  array<string, SectionNote>  $notes  section => what this run could not check
     *                                             there, or what narrows what it did. A
     *                                             section whose inputs were missing on
     *                                             *this* run — the document could not be
     *                                             read, so drift has nothing to compare —
     *                                             is named here; a section this release
     *                                             never checks is named in
     *                                             {@see self::notBuilt()} instead, since
     *                                             that answer does not depend on the run.
     */
    public function __construct(
        public string $specPath,
        public bool $specFileFound,
        public array $allowedHosts,
        public string $vendorPath,
        public ?SpecVersion $version,
        public array $routes,
        public array $findings,
        public array $notes = [],
    ) {}

    /**
     * Sections this release does not check at all, and why — the four
     * docs/guide/doctor.md lists that have no inputs yet.
     *
     * **Held here rather than in the formatter, because `--json` owes a
     * consumer the same answer the text report gives.** A section absent from
     * both is a section a green exit silently claims to have covered, which is
     * the one property the exit code rests on: doctor.md's own rule is that
     * "the report says which checks were skipped, on every run, so a green
     * exit is never mistaken for a full pass".
     *
     * A method rather than a constant because a class constant cannot hold an
     * object.
     *
     * @return array<string, SectionNote>
     *
     * @see docs/guide/doctor.md — "What it checks"
     */
    private static function notBuilt(): array
    {
        return [
            'Security' => SectionNote::notChecked(
                'not built yet — a `securitySchemes` name with no guard behind it, or a scheme type the '.
                'middleware cannot enforce, is not diagnosed by this release. See docs/guide/security.md and '.
                'docs/project/roadmap.md.'
            ),
            'Lifecycle' => SectionNote::notChecked(
                'not built yet — the `x-sunset` and `x-lifecycle` rules are not diagnosed by this release. See '.
                'docs/guide/lifecycle.md and docs/project/roadmap.md.'
            ),
            'Baseline' => SectionNote::notChecked(
                'not built yet — whether the previously committed specification can be read from git is not '.
                'diagnosed by this release. See docs/project/roadmap.md.'
            ),
            'Drivers' => SectionNote::notChecked(
                'not built yet — driver names and their mappings are not diagnosed by this release. See '.
                'docs/guide/drivers.md and docs/project/roadmap.md.'
            ),
        ];
    }

    /**
     * What this report has to say about a section beyond its findings, or null
     * when there is nothing to add.
     *
     * A run-specific note wins over the standing "not built yet" one: if a
     * section is both unbuilt and could not have run anyway, the reason
     * belonging to *this* run is the more useful of the two.
     */
    public function noteFor(string $section): ?SectionNote
    {
        return $this->notes[$section] ?? self::notBuilt()[$section] ?? null;
    }

    /**
     * Every section this report has a note for, in the order the sections
     * themselves run.
     *
     * @param  list<string>  $sectionOrder
     * @return array<string, SectionNote>
     */
    public function notesInOrder(array $sectionOrder): array
    {
        $notes = [];

        foreach ($sectionOrder as $section) {
            $note = $this->noteFor($section);

            if ($note !== null) {
                $notes[$section] = $note;
            }
        }

        return $notes;
    }

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
