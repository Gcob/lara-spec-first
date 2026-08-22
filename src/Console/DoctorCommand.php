<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use DateTimeImmutable;
use DateTimeZone;
use Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract;
use Gcob\LaraSpecFirst\Doctor\Checks\DocumentValidityCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\DriftCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\InstallationCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\LifecycleCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\ReferencesCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\RoutingOutcomeCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\SecurityCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\SupportMatrixCheck;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\ReportFormatter;
use Gcob\LaraSpecFirst\Doctor\RouteOutcome;
use Gcob\LaraSpecFirst\Doctor\SectionNote;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Routing\GeneratedRoutesLocator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

/**
 * `nginx -t` for your contract: what this package will honor, what it will
 * not, and the routing table that results.
 *
 * **Read-only, always.** It never writes a cache, never touches the
 * database, never mutates state — including the specification itself, and
 * including the generated tree {@see self::diagnose()}'s Routing outcome and
 * Drift sections only ever plan against, exactly as `spec:build` does before
 * it writes anything.
 *
 * @see docs/guide/doctor.md
 */
final class DoctorCommand extends Command
{
    use ReadsTheContract;

    /** @var string */
    protected $signature = 'spec:doctor
        {--spec= : Read this specification instead of the configured one}
        {--json : Machine-readable findings, for CI annotation and for tooling}';

    /** @var string */
    protected $description = 'Report everything this package will and will not honor in your OpenAPI contract';

    public function handle(Repository $config, RemoteReferenceGuard $remote): int
    {
        // The same contract every command of this package keeps: a refusal
        // this command cannot proceed past at all — an unset `spec.path`,
        // for instance — is a diagnostic, not a stack trace. What the
        // reading pipeline itself collects is different: it never throws
        // any more, and every fault it found becomes a Finding instead,
        // which is the entire reason this command exists.
        try {
            $report = $this->diagnose($config, $remote);
        } catch (SpecException $refusal) {
            // `--json` asks for output tooling can parse without guessing —
            // a plain-text error line here would be exactly the kind of
            // prose that flag exists to spare a consumer from.
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $refusal->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($refusal->getMessage());
            }

            return self::FAILURE;
        }

        $formatter = new ReportFormatter;
        $this->line($this->option('json') ? $formatter->toJson($report) : $formatter->toText($report));

        return $report->exitCode();
    }

    private function diagnose(Repository $config, RemoteReferenceGuard $remote): DiagnosticReport
    {
        $specPath = $this->specPath($config);
        $outcome = $this->readContract($specPath, $remote);

        // Read once and passed down rather than taken again inside each rule
        // that needs it: two lifecycle rules compare against "now", and a
        // report where the two disagreed — over a midnight, over a slow
        // read — would be a report about the clock rather than about the
        // contract. It is also what makes the check testable without
        // waiting for a date to arrive.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $findings = [
            ...DocumentValidityCheck::check($outcome),
            ...ReferencesCheck::check($outcome),
            ...SupportMatrixCheck::rejected($outcome),
            ...SupportMatrixCheck::missingOperationId($outcome->operations),
            ...SupportMatrixCheck::deferred($outcome->document),
            ...SecurityCheck::check($outcome->operations),
            ...LifecycleCheck::check($outcome->operations, $now),
        ];

        $routing = $this->routingOutcome($config, $outcome, $specPath);
        $findings = [...$findings, ...$routing['findings']];

        $findings = [...$findings, ...$this->installation($config)];

        return new DiagnosticReport(
            $specPath,
            is_file($specPath),
            self::stringList($config->get('lara-spec-first.remote_references.allowed_hosts', [])),
            (string) $config->get('lara-spec-first.remote_references.vendor_path', ''),
            $outcome->document?->version,
            $routing['routes'],
            $findings,
            $routing['notes'],
            // Null rather than an outcome full of zeroes when there was no
            // document: "0 of 0 public operations are stable" read off a file
            // that could not be opened is a statement about nothing, and a
            // consumer of `--json` would have to know to disbelieve it.
            $outcome->document === null
                ? null
                : LifecycleCheck::outcome(
                    $outcome->operations,
                    $now,
                    LifecycleCheck::horizonDays($config->get('lara-spec-first.lifecycle.sunset_horizon_days')),
                ),
            SecurityCheck::inheritsUnreadRootRequirements($outcome->document),
        );
    }

    /**
     * Routing outcome and Drift both need the same `BuildPlan` — one call to
     * `BuildPlanner::plan()` shared between the two rather than one each —
     * and both are skipped together the moment `generated.namespace` or the
     * generated tree's own location cannot be resolved at all: there is
     * nothing left for either section to compare against.
     *
     * **Drift needs one precondition more than Routing outcome does, and it is
     * this method's to check.** A document the pipeline could not read in full
     * yields fewer operations than it describes — none at all, when the parser
     * refused it outright — so the plan built from it is legitimately short,
     * and every generated file the plan no longer accounts for reads as
     * something a build would prune. It would not: `spec:build` refuses the
     * same document before writing anything. So one real fault became one real
     * fault plus one false line per generated file, each of them stating an
     * action that will not happen, with the line that matters scrolled off the
     * top. Drift is the gap between a specification and a build that already
     * happened; a specification that cannot be read is not one end of that gap.
     *
     * Said rather than silently dropped, per {@see DiagnosticReport::$notes}:
     * a section that prints `clean` because it never ran is the same defect in
     * the other direction.
     *
     * @return array{routes: list<RouteOutcome>, findings: list<Finding>, notes: array<string, SectionNote>}
     */
    private function routingOutcome(Repository $config, ReadOutcome $read, string $specPath): array
    {
        try {
            $namespace = $this->requiredString($config, 'lara-spec-first.generated.namespace');
            $configuredPath = $config->get(GeneratedRoutesLocator::SETTING);
            $locator = GeneratedRoutesLocator::fromConfiguration($this->laravel->basePath(), $configuredPath);
        } catch (SpecException $refusal) {
            return [
                'routes' => [],
                'findings' => [new Finding(FindingClass::PackageLimit, 'Routing outcome', null, '', $refusal->getMessage())],
                'notes' => [
                    'Drift' => SectionNote::notChecked(
                        'the generated tree\'s own location could not be resolved, so there is nothing to '.
                        'compare a build against. See Routing outcome.'
                    ),
                ],
            ];
        }

        $outcome = RoutingOutcomeCheck::check($read->operations, $namespace, $specPath);

        if (! $read->isClean()) {
            return [
                'routes' => $outcome->routes,
                'findings' => $outcome->findings,
                'notes' => [
                    'Routing outcome' => SectionNote::narrowed(
                        'only the operations that could be extracted are listed — the document carries at least '.
                        'one fault, so this table may describe less than the specification does.'
                    ),
                    'Drift' => SectionNote::notChecked(
                        'the document could not be read in full, and `spec:build` would refuse it before '.
                        'writing anything, so nothing here would be written or pruned. Fix the faults above '.
                        'and run the doctor again.'
                    ),
                ],
            ];
        }

        $driftFindings = DriftCheck::check($outcome->plan, dirname($locator->path()));

        return [
            'routes' => $outcome->routes,
            'findings' => [...$outcome->findings, ...$driftFindings],
            'notes' => [],
        ];
    }

    /**
     * Independent of Routing outcome and Drift on purpose: neither needs a
     * `BuildPlan`, so a document this package cannot build a plan from —
     * where those two sections have nothing left to say — still gets its
     * installation checked.
     *
     * @return list<Finding>
     */
    private function installation(Repository $config): array
    {
        $namespace = $config->get('lara-spec-first.generated.namespace');
        $path = $config->get(GeneratedRoutesLocator::SETTING);

        if (! is_string($namespace) || trim($namespace) === '' || ! is_string($path) || trim($path) === '') {
            return [];
        }

        return InstallationCheck::check($this->laravel->basePath(), (string) $config->get('lara-spec-first.remote_references.vendor_path', ''), $namespace, $path);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
