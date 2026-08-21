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
 * Version, References, Support findings, Routing outcome, Drift,
 * Installation. A section with nothing to say still prints, because a
 * command that prints nothing on success teaches a developer nothing about
 * what the spec actually did — the same reasoning doctor.md gives for
 * reporting the outcome, not only the problems.
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
        'Drift',
        'Installation',
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
            'findings' => array_map(static fn (Finding $finding): array => [
                'class' => $finding->class->value,
                'section' => $finding->section,
                'level' => $finding->level?->value,
                'pointer' => $finding->pointer,
                'message' => $finding->message,
            ], $report->findings),
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
        $lines[] = '  '.($report->version->value ?? '(unknown — see Document validity)');
        $lines[] = '';

        foreach (self::SECTION_ORDER as $section) {
            $findings = self::findingsIn($report, $section);

            $lines[] = $section;

            // The routing table is the outcome, not a problem — it prints
            // whether or not the section also carries a finding, which is
            // why it is not folded into the "clean" placeholder below.
            if ($section === 'Routing outcome') {
                array_push($lines, ...self::routes($report));
            }

            if ($findings === []) {
                if ($section !== 'Routing outcome' || $report->routes === []) {
                    $lines[] = '  clean';
                }
            } else {
                foreach ($findings as $finding) {
                    $lines[] = '  '.self::badge($finding).' '.$finding->message;
                }
            }

            $lines[] = '';
        }

        $lines[] = self::summary($report);

        return implode("\n", $lines);
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

        return match ($finding->class) {
            FindingClass::DocumentFault => '[document fault]',
            FindingClass::PackageLimit => sprintf('[package limit: %s]', $finding->level->value ?? 'n/a'),
        };
    }

    private static function summary(DiagnosticReport $report): string
    {
        $deferred = count(array_filter(
            $report->findings,
            static fn (Finding $finding): bool => $finding->level === SupportLevel::Deferred,
        ));

        if ($report->isClean()) {
            return $deferred === 0
                ? 'Clean. Nothing here is being dropped without a decision behind it.'
                : sprintf(
                    'Clean. %d deferred item(s) above are a fact about this package\'s roadmap, not a reason to fail.',
                    $deferred,
                );
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

        return sprintf(
            '%d document fault(s), %d package limit(s).',
            $faults,
            $limits,
        );
    }
}
