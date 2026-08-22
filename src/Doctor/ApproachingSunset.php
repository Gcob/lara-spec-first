<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * One operation whose stated removal date falls inside the configured
 * horizon, and how long is left.
 *
 * Not a {@see Finding}, on purpose: see {@see LifecycleOutcome} for why an
 * approaching date is reported as an outcome rather than as something that
 * fails a run.
 */
final readonly class ApproachingSunset
{
    public function __construct(
        public string $operation,
        public string $sunset,
        public int $daysRemaining,
    ) {}
}
