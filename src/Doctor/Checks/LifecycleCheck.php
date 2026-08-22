<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Doctor\ApproachingSunset;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\LifecycleOutcome;

/**
 * The rules the lifecycle extensions this package defines are worth defining
 * for: a deprecation is a commitment with a date attached, a date that has
 * passed is an endpoint still being served after its own removal, and a date
 * nothing can read is a promise no consumer can plan around.
 *
 * Two halves, deliberately not one:
 *
 *   - {@see self::check()} returns findings, and every one of them gates the
 *     exit code. All three are document faults — only the author of the
 *     contract can fix any of them.
 *   - {@see self::outcome()} returns what the section reports when nothing is
 *     wrong: the `beta` listing, the sunsets coming up, and the protection
 *     report. See {@see LifecycleOutcome} for why an approaching date belongs
 *     there rather than among the findings.
 *
 * **One rule from docs/guide/lifecycle.md is deliberately absent here**: an
 * unrecognized `x-lifecycle` value. `Parsing\OperationExtractor` already
 * refuses it, so it reaches the report as a fault the reading pipeline
 * collected and lands under Document validity, where every other refusal of
 * that class lands. Reporting it again here would be the same problem stated
 * twice in one report.
 *
 * `public` + `stable` without an `operationId` is absent for the same kind of
 * reason: it is already {@see SupportMatrixCheck::missingOperationId()}.
 *
 * @see docs/guide/lifecycle.md — "The doctor rules that follow"
 */
final readonly class LifecycleCheck
{
    private const SECTION = 'Lifecycle';

    /**
     * What the horizon falls back to when configuration says nothing usable.
     * A doctor that refuses to run over its own configuration would be a poor
     * diagnostic tool.
     */
    public const DEFAULT_HORIZON_DAYS = 90;

    /**
     * The configured horizon, or the default when the configured value is not
     * a whole number of days.
     *
     * **A whole number, deliberately, and not merely "something numeric".**
     * `90.5` and `1e2` are numeric and would be silently truncated into a
     * different horizon than the one written, which is the kind of quiet
     * reinterpretation a configuration file should never do. A string of
     * digits is accepted because an environment variable has no other way to
     * carry an integer.
     *
     * Anything else — a float, a negative count of days, a typo — falls back
     * rather than refusing to run: this key only ever decides what is
     * *mentioned*, so a diagnostic tool that would not diagnose because of it
     * is the worse failure of the two. `0` is a real value, not a fallback:
     * it prints no upcoming date at all.
     */
    public static function horizonDays(mixed $configured): int
    {
        if (is_int($configured) && $configured >= 0) {
            return $configured;
        }

        if (is_string($configured) && preg_match('/^\d+$/', trim($configured)) === 1) {
            return (int) trim($configured);
        }

        return self::DEFAULT_HORIZON_DAYS;
    }

    /**
     * @param  list<Operation>  $operations
     * @return list<Finding>
     */
    public static function check(array $operations, DateTimeImmutable $now): array
    {
        $findings = [];

        foreach ($operations as $operation) {
            $sunset = $operation->sunset;
            $moment = self::moment($sunset);

            if ($operation->deprecated && $sunset === null) {
                $findings[] = self::fault(sprintf(
                    '%s is marked deprecated and states no x-sunset. A deprecation with no removal date is a wish: '.
                    'nothing in your contract, and nothing a consumer of it reads, says when this endpoint stops '.
                    'being served.',
                    $operation->label(),
                ));
            }

            if ($sunset !== null && $moment === null) {
                $findings[] = self::fault(sprintf(
                    '%s declares x-sunset: %s, which is not a date this package can read. Write it as a calendar '.
                    'date (2026-06-01) or as a moment (2026-06-01T00:00:00Z); anything else is resolved against the '.
                    'day the build happens to run, which is not a promise.',
                    $operation->label(),
                    $sunset,
                ));
            }

            if ($moment !== null && $moment < $now) {
                $findings[] = self::fault(sprintf(
                    '%s declares x-sunset: %s, which has passed, and the endpoint is still served. Nothing else in '.
                    'this system will ever notice: remove the operation, or move the date and say so.',
                    $operation->label(),
                    (string) $sunset,
                ));
            }
        }

        return $findings;
    }

    /**
     * @param  list<Operation>  $operations
     * @param  int  $horizonDays  how far ahead a removal date is worth mentioning.
     *                            `0` prints no upcoming date at all, and changes
     *                            nothing about the rules that gate
     */
    public static function outcome(array $operations, DateTimeImmutable $now, int $horizonDays): LifecycleOutcome
    {
        $beta = [];
        $approaching = [];
        $public = 0;
        $stable = 0;

        foreach ($operations as $operation) {
            if ($operation->lifecycle === Lifecycle::Beta) {
                $beta[] = $operation->label();
            }

            if ($operation->audience === Audience::Public) {
                $public++;

                if ($operation->lifecycle === Lifecycle::Stable) {
                    $stable++;
                }
            }

            $moment = self::moment($operation->sunset);

            if ($horizonDays <= 0 || $moment === null || $moment < $now) {
                continue;
            }

            $remaining = (int) $now->diff($moment)->days;

            if ($remaining <= $horizonDays) {
                $approaching[] = new ApproachingSunset($operation->label(), (string) $operation->sunset, $remaining);
            }
        }

        return new LifecycleOutcome($beta, $approaching, $public, $stable);
    }

    /**
     * The moment an already-normalized `x-sunset` states, or null when the
     * document wrote something no date can be read out of.
     *
     * `Parsing\OperationExtractor` has already done the reading: a value it
     * could make sense of arrives in one of exactly two spellings — `Y-m-d`
     * when the moment lands on midnight UTC, `DateTimeInterface::ATOM`
     * otherwise — and a value it could not arrives verbatim, precisely so this
     * section can report it rather than the pipeline refusing the whole
     * contract over a date. So this accepts those two spellings and nothing
     * else — it is not a second, looser parser sitting beside the first, which
     * is what accepting whatever `new DateTimeImmutable()` swallows would make
     * it.
     */
    private static function moment(?string $sunset): ?DateTimeImmutable
    {
        if ($sunset === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        // Two formats, matching the two spellings named above. There used to
        // be a third for `…T00:00:00Z`, and it was unreachable twice over: the
        // extractor never emits `Z` — it normalizes to `Y-m-d` on midnight UTC
        // and to ATOM otherwise, and ATOM writes a UTC offset as `+00:00` — and
        // PHP's `P` specifier reads `Z` back anyway when parsing, so ATOM
        // covers that spelling on its own. A third format was a claim about a
        // path through the pipeline that does not exist, guarding a case the
        // second format already handled, which is exactly the second looser
        // parser this method refuses to be. Both halves are pinned by a test.
        foreach (['!Y-m-d', DateTimeInterface::ATOM] as $format) {
            $moment = DateTimeImmutable::createFromFormat($format, $sunset, $utc);
            $errors = DateTimeImmutable::getLastErrors();

            // A parse that only succeeded by rolling — `2026-02-30` becoming
            // the second of March — carries warnings, and answering a claim
            // its author did not make is worse than reporting the date as
            // unreadable. Exactly the rule `OperationExtractor` applies before
            // this.
            if ($moment !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $moment->setTimezone($utc);
            }
        }

        return null;
    }

    private static function fault(string $message): Finding
    {
        // Always a document fault, never a package limit: this package honors
        // the lifecycle keys it defines, and every one of these three is the
        // contract contradicting itself. A support level would be the wrong
        // vocabulary for that, so there is none — the same as every other
        // document fault in this report.
        return new Finding(FindingClass::DocumentFault, self::SECTION, null, '', $message);
    }
}
