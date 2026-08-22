<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * Marker for a fault that leaves the document unsafe to hand to the parser.
 *
 * Most document faults are survivable: `cebe\openapi\` is still given the
 * document, still builds what it can, and whatever it refuses is collected
 * beside the rest. A few are not, and the difference is not "how bad the
 * document is" but "what the parser does with it": it recurses past its own
 * guards and exhausts memory rather than raising, so there is no exception left
 * to catch and no process left to report with.
 *
 * **A marker rather than a list of class names at the call site.** The one place
 * that has to know — {@see ReadOutcome::read()}, which decides whether the
 * extractor may run — used to name {@see CyclicReferenceException} directly. The
 * [conformance suite](../../../tests/Conformance) already records two more
 * document shapes that kill the parser the same way, neither of them detectable
 * today; the moment one becomes detectable, an `instanceof` on a concrete class
 * is the line nobody remembers to update, and the symptom is a dead process
 * rather than a failing assertion. Implementing this interface is what makes
 * that case additive instead — the same reasoning that gave
 * {@see SpecException} its own marker.
 *
 * @see docs/guide/openapi-support.md — "Parser caveats"
 */
interface ParserUnsafeFault extends SpecException {}
