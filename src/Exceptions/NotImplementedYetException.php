<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Exceptions;

use RuntimeException;

/**
 * A setting exists, the behaviour behind it does not.
 *
 * magik is coming soon.
 *
 * Thrown rather than ignored on purpose. A configuration key that is read and
 * quietly does nothing is worse than one that is missing: the developer who
 * sets it has every reason to believe it took effect, and the package that
 * accepted it has told them so. Refusing loudly keeps the gap between what is
 * documented and what is built visible from the inside, not only in a roadmap.
 *
 * Every use of this is a promise with a date attached — it names the roadmap
 * item that removes it.
 */
final class NotImplementedYetException extends RuntimeException implements SpecException
{
    public static function setting(string $key, string $whatItWillDo, string $roadmapItem): self
    {
        return new self(sprintf(
            'The setting "%s" is not implemented yet, so this package will not pretend it took '.
            'effect. It will %s. Until then the only supported value is the default. See "%s" in '.
            'docs/ROADMAP.md.',
            $key,
            $whatItWillDo,
            $roadmapItem
        ));
    }
}
