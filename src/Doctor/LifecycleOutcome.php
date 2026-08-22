<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor;

/**
 * What the Lifecycle section reports without anything being wrong: the
 * unstable surface of the API, the removal dates coming up, and how much of
 * the contract is actually protected by a promise.
 *
 * **None of this is a {@see Finding}, and that is a decision rather than an
 * omission.** Every finding that is not `Deferred` gates the exit code, so an
 * approaching sunset expressed as a finding would turn a repository that was
 * green on Monday red on Tuesday with no commit in between — a failure nobody
 * caused, which is the fastest way to teach a team to ignore this command.
 * The report already carries a precedent for saying something useful without
 * failing over it: the resolved routing table. This is the same shape.
 *
 * The rules that *do* gate — a deprecation with no removal date, a date
 * already passed, a date that cannot be read — stay findings, and live in
 * {@see Checks\LifecycleCheck::check()}.
 *
 * @see docs/guide/lifecycle.md — "The doctor rules that follow"
 */
final readonly class LifecycleOutcome
{
    /**
     * @param  list<string>  $beta  every operation carrying `x-lifecycle: beta`, effective
     *                              rather than as written — a public operation that states
     *                              nothing is `beta`, which is the whole reason this list
     *                              is worth printing
     * @param  list<ApproachingSunset>  $approachingSunsets  in document order
     * @param  int  $publicOperations  public operations only. A monolith's two hundred
     *                                 internal routes must not drown out its "0 of 47"
     * @param  int  $stablePublicOperations  of those, the ones carrying a promise
     */
    public function __construct(
        public array $beta,
        public array $approachingSunsets,
        public int $publicOperations,
        public int $stablePublicOperations,
    ) {}
}
