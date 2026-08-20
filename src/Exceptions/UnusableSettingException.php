<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Exceptions;

use InvalidArgumentException;

/**
 * A setting the package needs carries a value it cannot work with.
 *
 * The sibling of {@see NotImplementedYetException}, and the distinction is whose
 * fault it is: that one refuses a setting the package has not built, this one
 * refuses a value the application supplied. Both exist so that a configuration
 * key never quietly means nothing.
 *
 * Distinct from a resource being absent, which is not always a fault. A missing
 * generated routes file is a legitimate state on a fresh clone; a `generated.path`
 * with nothing in it is a broken installation, because nothing can be looked for
 * without a path and carrying on would register no route on an application that
 * asked for some.
 */
final class UnusableSettingException extends InvalidArgumentException implements SpecException
{
    /**
     * @param  string  $key  the configuration key, so the message names what to edit
     * @param  string  $requirement  what a usable value has to be, in a form that
     *                               completes "must be"
     */
    public static function setting(string $key, string $requirement): self
    {
        return new self(sprintf(
            'The setting "%s" must be %s. This package cannot continue without it, because '.
            'carrying on would silently do nothing where the setting says it should do something. '.
            'Set it in config/lara-spec-first.php, or remove the key to fall back to the '.
            'package default.',
            $key,
            $requirement
        ));
    }
}
