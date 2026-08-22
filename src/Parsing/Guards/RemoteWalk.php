<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * The mutable state of one {@see RemoteReferenceGuard::resolve()} call.
 *
 * **An object rather than three more by-reference parameters.** The walk is
 * recursive and has to carry three things across every frame — what this call
 * already decided about a URL, the faults collected so far, and whether the
 * document was rewritten — and threading those as `&$seen, &$faults,
 * &$neutralized` beside the node, the base directory, the flag and the chain is
 * where a swapped argument stops being caught by a type.
 *
 * Deliberately not readonly and deliberately not a value object: this *is* the
 * accumulator, and it lives exactly as long as the one `resolve()` call that
 * created it.
 *
 * @internal Not public API — the traversal state of one guard call.
 */
final class RemoteWalk
{
    /**
     * One URL => what this call already decided about it: `true` once it is
     * vendored, or the fault that refused it.
     *
     * **Both outcomes are remembered, not only the success.** A specification
     * naming the same reference from a dozen positions must fetch it once — and
     * must also *refuse* it once. Twelve identical faults for one bad URL is
     * twelve lines saying the same thing in a report meant to be read, and,
     * under `--update-refs`, twelve requests to a host that already failed the
     * first time.
     *
     * Keyed by URL with the fragment stripped, because the fragment is not
     * fetched: `s.yaml#/A` and `s.yaml#/B` are one reference to refuse or
     * vendor, and the fault names whichever position reached it first.
     *
     * @var array<string, SpecException|true>
     */
    public array $seen = [];

    /** @var list<SpecException> */
    public array $faults = [];

    /**
     * Whether any reference was removed from the document — see
     * {@see RemoteReferenceGuard::walk()} for what removal means, and
     * {@see ReadOutcome} for why a caller needs to know.
     */
    public bool $neutralized = false;

    public function faultCount(): int
    {
        return count($this->faults);
    }

    /**
     * Record a fault, against the URL that caused it.
     *
     * Returns null because that is what every caller does with it: refusing a
     * reference and telling {@see RemoteReferenceGuard::walk()} to neutralize
     * it are the same act.
     */
    public function refuse(string $url, SpecException $fault): null
    {
        $this->faults[] = $fault;
        $this->seen[$url] = $fault;

        return null;
    }

    /**
     * Refuse a URL whose fault a deeper frame already recorded.
     *
     * A vendored document that could not be fully resolved is refused by its
     * parent too — see {@see RemoteReferenceGuard::vendor()} — but the fault
     * that says why was already collected inside it, and adding it twice would
     * report one problem as two.
     */
    public function refuseSilently(string $url, SpecException $fault): null
    {
        $this->seen[$url] ??= $fault;

        return null;
    }
}
