<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console;

use Gcob\LaraSpecFirst\Console\Concerns\ReadsTheContract;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Doctor\Checks\DocumentValidityCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\DriftCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\InstallationCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\ReferencesCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\RoutingOutcomeCheck;
use Gcob\LaraSpecFirst\Doctor\Checks\SupportMatrixCheck;
use Gcob\LaraSpecFirst\Doctor\DiagnosticReport;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\ReportFormatter;
use Gcob\LaraSpecFirst\Doctor\RouteOutcome;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
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

        $findings = [
            ...DocumentValidityCheck::check($outcome),
            ...ReferencesCheck::check($outcome),
            ...SupportMatrixCheck::rejected($outcome),
            ...SupportMatrixCheck::missingOperationId($outcome->operations),
            ...SupportMatrixCheck::deferred($outcome->document),
        ];

        $routing = $this->routingOutcome($config, $outcome->operations, $specPath);
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
        );
    }

    /**
     * Routing outcome and Drift both need the same `BuildPlan` — one call to
     * `BuildPlanner::plan()` shared between the two rather than one each —
     * and both are skipped together the moment `generated.namespace` or the
     * generated tree's own location cannot be resolved at all: there is
     * nothing left for either section to compare against.
     *
     * @param  list<Operation>  $operations
     * @return array{routes: list<RouteOutcome>, findings: list<Finding>}
     */
    private function routingOutcome(Repository $config, array $operations, string $specPath): array
    {
        try {
            $namespace = $this->requiredString($config, 'lara-spec-first.generated.namespace');
            $configuredPath = $config->get(GeneratedRoutesLocator::SETTING);
            $locator = GeneratedRoutesLocator::fromConfiguration($this->laravel->basePath(), $configuredPath);
        } catch (SpecException $refusal) {
            return [
                'routes' => [],
                'findings' => [new Finding(FindingClass::PackageLimit, 'Routing outcome', null, '', $refusal->getMessage())],
            ];
        }

        $outcome = RoutingOutcomeCheck::check($operations, $namespace, $specPath);
        $driftFindings = DriftCheck::check($outcome->plan, dirname($locator->path()));

        return [
            'routes' => $outcome->routes,
            'findings' => [...$outcome->findings, ...$driftFindings],
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
