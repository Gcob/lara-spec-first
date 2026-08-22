<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * Renders a DiagnosticReport as text for the console.
 *
 * A plain string rather than writing straight to a Command's output: the same
 * report has to become `--json` too, and a formatter that only knows how to
 * print is a formatter `--json` cannot reuse. See docs/guide/doctor.md — "The
 * contract" — for why both are owed from the first release.
 *
 * **Section order is fixed**, matching the table in
 * docs/guide/doctor.md#what-it-checks: Configuration, Document validity,
 * Version, References, Support findings, Routing outcome, Security, Drift,
 * Lifecycle, Installation, Baseline, Drivers. A section with nothing to say
 * still prints, because a command that prints nothing on success teaches a
 * developer nothing about what the spec actually did — the same reasoning
 * doctor.md gives for reporting the outcome, not only the problems.
 *
 * **Every section prints, including the four this release does not check.**
 * A section absent from the report is one a green exit silently claims to
 * have covered — and the four that are absent today are not minor ones:
 * doctor.md calls Security "the one finding that can turn a
 * documented-as-protected endpoint into a public one". So each prints its
 * note instead of `clean`, from {@see DiagnosticReport::noteFor()}, which is
 * also where a section skipped on *this* run says so. That is doctor.md's own
 * rule about the exit code: the report says which checks were skipped, on
 * every run, so a green exit is never mistaken for a full pass.
 */
final readonly class ReportFormatter
{
    /**
     * The section order every render follows, whether or not this PR's
     * checks have populated all of them yet.
     */
    private const SECTION_ORDER = [
        'Document validity',
        'References',
        'Support findings',
        'Routing outcome',
        'Security',
        'Drift',
        'Lifecycle',
        'Installation',
        'Baseline',
        'Drivers',
    ];

    /**
     * A flat object per finding, plus a summary — for CI annotation and for
     * tooling, including an AI agent, that consumes findings without parsing
     * prose. See docs/guide/doctor.md — "The contract".
     */
    public function toJson(DiagnosticReport $report): string
    {
        $payload = [
            'spec' => $report->specPath,
            'specFileFound' => $report->specFileFound,
            'version' => $report->version?->value,
            'configuration' => [
                'allowedHosts' => $report->allowedHosts,
                'vendorPath' => $report->vendorPath,
            ],
            'routes' => array_map(static fn (RouteOutcome $route): array => [
                'method' => $route->method,
                'path' => $route->path,
                'target' => $route->target,
                'routesToCustomController' => $route->routesToCustomController,
            ], $report->routes),
            // The same outcome the text report prints under Lifecycle, and
            // for the same reason it is not a finding: a CI script that
            // wants to act on an approaching removal date can, without the
            // exit code having decided for it.
            'lifecycle' => $report->lifecycle === null ? null : [
                'beta' => $report->lifecycle->beta,
                'approachingSunsets' => array_map(static fn (ApproachingSunset $sunset): array => [
                    'operation' => $sunset->operation,
                    'sunset' => $sunset->sunset,
                    'daysRemaining' => $sunset->daysRemaining,
                ], $report->lifecycle->approachingSunsets),
                'publicOperations' => $report->lifecycle->publicOperations,
                'stablePublicOperations' => $report->lifecycle->stablePublicOperations,
            ],
            'inheritsUnreadRootRequirements' => $report->inheritsUnreadRootRequirements,
            'findings' => array_map(static fn (Finding $finding): array => [
                'class' => $finding->class->value,
                'section' => $finding->section,
                'level' => $finding->level?->value,
                'pointer' => $finding->pointer,
                'message' => $finding->message,
            ], $report->findings),
            // Named `notes` rather than `skipped` because it carries both: a
            // section this release never checks, and a section whose inputs
            // this run did not have. A consumer gating on the exit code needs
            // to be able to tell what that code covered, and a key that is
            // present only sometimes is a key nobody discovers until it is —
            // so it is always emitted, empty object included.
            'notes' => array_map(static fn (SectionNote $note): array => [
                'checked' => $note->checked,
                'note' => $note->note,
            ], $report->notesInOrder(self::SECTION_ORDER)),
            'summary' => [
                'exitCode' => $report->exitCode(),
                'clean' => $report->isClean(),
            ],
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toText(DiagnosticReport $report): string
    {
        $lines = [];

        $lines[] = 'Configuration';
        $lines[] = '  spec: '.$report->specPath.($report->specFileFound ? '' : ' (not found)');
        $lines[] = '  remote_references.allowed_hosts: '.($report->allowedHosts === [] ? '(none)' : implode(', ', $report->allowedHosts));
        $lines[] = '  remote_references.vendor_path: '.$report->vendorPath;
        $lines[] = '';

        $lines[] = 'Version';
        // Written as an explicit null check rather than `->value ?? …`: both
        // properties this report reads that way are declared nullable, and
        // `??`'s isset semantics quietly answer the question instead of asking
        // it. `?->` on the left of `??` is redundant enough that PHPStan
        // refuses it, so the comparison is the one spelling that states the
        // nullability and passes level 8.
        $lines[] = '  '.($report->version === null ? '(unknown — see Document validity)' : $report->version->value);
        $lines[] = '';

        foreach (self::SECTION_ORDER as $section) {
            $findings = self::findingsIn($report, $section);
            $note = $report->noteFor($section);

            $lines[] = $section;

            // What a section reports when nothing is wrong — the routing
            // table, the lifecycle listings — is the outcome, not a problem.
            // It prints whether or not the section also carries a finding,
            // which is why it is not folded into the "clean" placeholder
            // below and why "clean" gives way to it.
            $outcome = self::outcomeOf($report, $section);
            array_push($lines, ...$outcome);

            foreach ($findings as $finding) {
                $lines[] = '  '.self::badge($finding).' '.$finding->message;
            }

            if ($note !== null) {
                $lines[] = '  '.($note->checked ? '[note]' : '[not checked]').' '.$note->note;
            }

            // `clean` is a claim, so it is only made when the section actually
            // ran, found nothing, and had no outcome of its own to show. A
            // section carrying a note either did not run or ran on less than it
            // needed, and printing both would say two different things about
            // the same section. `$outcome === []` covers the routing table and
            // the lifecycle listings both, rather than naming one section.
            if ($findings === [] && $note === null && $outcome === []) {
                $lines[] = '  clean';
            }

            $lines[] = '';
        }

        $lines[] = self::summary($report);

        return implode("\n", $lines);
    }

    /**
     * The lines a section prints that are not findings: what the run found to
     * be true rather than what it found to be wrong.
     *
     * @return list<string>
     */
    private static function outcomeOf(DiagnosticReport $report, string $section): array
    {
        return match ($section) {
            'Routing outcome' => self::routes($report),
            'Security' => self::rootRequirements($report),
            'Lifecycle' => self::lifecycle($report),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private static function routes(DiagnosticReport $report): array
    {
        $lines = [];

        foreach ($report->routes as $route) {
            $lines[] = sprintf(
                '  %-6s %-40s %s%s',
                $route->method,
                $route->path,
                $route->target,
                $route->routesToCustomController ? '' : ' (501, unimplemented)',
            );
        }

        return $lines;
    }

    /**
     * One line, never a list of the operations under it: the package does not
     * read the root block, so it cannot say what any of them inherits from it.
     *
     * @return list<string>
     */
    private static function rootRequirements(DiagnosticReport $report): array
    {
        if (! $report->inheritsUnreadRootRequirements) {
            return [];
        }

        return ['  This document declares a root security block. It is not read: an operation that states no '.
            'security of its own inherits requirements nothing here resolved, so no such operation is named in this '.
            'section.'];
    }

    /**
     * The protection report on every run, then the unstable surface and the
     * removal dates coming up. Printed even when all three are empty, because
     * "0 of 47 public operations are stable" is precisely the run where
     * nothing is wrong and everything is unprotected.
     *
     * @return list<string>
     */
    private static function lifecycle(DiagnosticReport $report): array
    {
        $outcome = $report->lifecycle;

        // Nothing read, or nothing promised to anyone: "0 of 0 public
        // operations are stable" is a sentence about an empty document
        // rather than about a contract, and the section says "clean"
        // instead. The case this line exists for — public operations with
        // no promise on them — still prints, because $publicOperations is
        // what makes it non-empty.
        if ($outcome === null || ($outcome->publicOperations === 0 && $outcome->beta === [] && $outcome->approachingSunsets === [])) {
            return [];
        }

        $lines = [sprintf(
            '  %d of %d public operation(s) are stable.%s',
            $outcome->stablePublicOperations,
            $outcome->publicOperations,
            $outcome->publicOperations > 0 && $outcome->stablePublicOperations === 0
                ? ' Nothing in this contract is promised, so nothing in it can be broken by accident.'
                : '',
        )];

        foreach ($outcome->beta as $operation) {
            $lines[] = '  beta: '.$operation;
        }

        foreach ($outcome->approachingSunsets as $sunset) {
            $lines[] = sprintf('  sunset in %d day(s): %s on %s', $sunset->daysRemaining, $sunset->operation, $sunset->sunset);
        }

        return $lines;
    }

    /**
     * @return list<Finding>
     */
    private static function findingsIn(DiagnosticReport $report, string $section): array
    {
        return array_values(array_filter(
            $report->findings,
            static fn (Finding $finding): bool => $finding->section === $section,
        ));
    }

    private static function badge(Finding $finding): string
    {
        // Its own badge, deliberately not `[package limit: deferred]`: a
        // package limit is a gating finding, and Deferred never gates —
        // wearing that badge anyway would tell a reader, and a
        // string-matching CI script, that this is the failure it explicitly
        // is not.
        if ($finding->level === SupportLevel::Deferred) {
            return '[deferred]';
        }

        // The level prints on a document fault too when there is one. It used
        // to be dropped here and survive only in `--json`, which made the
        // `operationId` finding — the one document fault that carries a level —
        // print without the `Partial` that motivates it, while doctor.md
        // promises the level on every finding.
        return match ($finding->class) {
            FindingClass::DocumentFault => $finding->level === null
                ? '[document fault]'
                : sprintf('[document fault: %s]', $finding->level->value),
            FindingClass::PackageLimit => sprintf(
                '[package limit: %s]',
                $finding->level === null ? 'n/a' : $finding->level->value,
            ),
        };
    }

    private static function summary(DiagnosticReport $report): string
    {
        $deferred = count(array_filter(
            $report->findings,
            static fn (Finding $finding): bool => $finding->level === SupportLevel::Deferred,
        ));

        if ($report->isClean()) {
            return self::withUncheckedSections($report, $deferred === 0
                ? 'Clean. Nothing here is being dropped without a decision behind it.'
                : sprintf(
                    'Clean. %d deferred item(s) above are a fact about this package\'s roadmap, not a reason to fail.',
                    $deferred,
                ));
        }

        // Deferred findings are excluded from both counts below, the same
        // way they are excluded from the exit code: a `Deferred` line is
        // never a fault or a limit in the sense this sentence means.
        $faults = count(array_filter(
            $report->findings,
            static fn (Finding $finding): bool => $finding->class === FindingClass::DocumentFault
                && $finding->level !== SupportLevel::Deferred,
        ));
        $limits = count($report->findings) - $faults - $deferred;

        return self::withUncheckedSections($report, sprintf(
            '%d document fault(s), %d package limit(s).',
            $faults,
            $limits,
        ));
    }

    /**
     * The summary line, followed by the sections this run did not cover.
     *
     * **The exit code's scope, on the line a reader actually reads.** Every
     * unchecked section already prints its own note above, but the summary is
     * the line a person skims and a CI log tails — and `Clean.` on its own,
     * from a run that never looked at Security, is the sentence doctor.md
     * forbids: a green exit mistaken for a full pass.
     */
    private static function withUncheckedSections(DiagnosticReport $report, string $summary): string
    {
        // Only the sections that did not run at all. A section that ran on a
        // partial document carries real findings, and naming it "not covered"
        // beside them would understate what it did find — which is why
        // {@see SectionNote} carries the distinction rather than one string
        // having to imply it.
        $sections = array_keys(array_filter(
            $report->notesInOrder(self::SECTION_ORDER),
            static fn (SectionNote $note): bool => ! $note->checked,
        ));

        if ($sections === []) {
            return $summary;
        }

        return $summary."\n".sprintf(
            'Not covered by this run: %s. See the [not checked] line in each.',
            implode(', ', $sections),
        );
    }
}
