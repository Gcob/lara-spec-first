<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Where a generated controller's name came from, which is what decides whether
 * anything may extend it.
 *
 * **Three sources, and the difference is not bookkeeping.** A name is either one
 * a developer chose in order to name a class, one they chose for another purpose,
 * or one nobody chose at all — and only the first can be depended on:
 *
 * - `x-controller` exists to name this operation's controller and has no other
 *   meaning in the contract, so nothing else in the document can move it. That is
 *   what makes the generated parent extendable.
 * - `operationId` is a label with its own job. It can be renamed, and the doctor
 *   actively encourages adding one where it is missing — so deriving an
 *   extendable name from it would mean this package nudging a project toward
 *   breaking its own imports.
 * - A name derived from the method and path was chosen by nobody, and moves
 *   whenever the URL does.
 *
 * @see docs/guide/controllers.md — "The specification decides what is customizable"
 */
enum NameSource: string
{
    case CustomController = 'x-controller';
    case OperationId = 'operationId';
    case Derived = 'derived';

    /**
     * Whether a name from this source may be extended.
     *
     * The one place that answer lives, so `final` in the emitter and the route's
     * choice of target cannot drift apart.
     */
    public function isExtendable(): bool
    {
        return $this === self::CustomController;
    }
}
