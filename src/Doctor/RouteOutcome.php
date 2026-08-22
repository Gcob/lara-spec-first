<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * One route as `spec:build` would actually register it — method, path and the
 * class it points at — which is the single most useful thing this command can
 * print, per docs/guide/doctor.md: most runs are clean, and a report that
 * only ever names problems teaches nothing about what the spec did.
 */
final readonly class RouteOutcome
{
    public function __construct(
        public string $method,
        public string $path,
        public string $target,
        public bool $routesToCustomController,
    ) {}
}
