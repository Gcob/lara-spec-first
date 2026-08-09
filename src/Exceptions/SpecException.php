<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Exceptions;

use Throwable;

/**
 * Marker for every exception this package throws.
 *
 * A consuming application should be able to catch everything that originates
 * here with a single `catch`, without knowing which part of the package failed.
 * Concrete exceptions still extend the SPL class that describes them best.
 */
interface SpecException extends Throwable {}
