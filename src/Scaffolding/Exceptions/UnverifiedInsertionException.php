<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Scaffolding\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * An automatic edit to the specification did not prove itself, so nothing was
 * written.
 *
 * **The verification failing is not the failure mode this class is designed
 * around** — it is the one it exists to make impossible to miss. The edit happens
 * on a copy that is read back through the normal pipeline, and every outcome except
 * "identical but for the extension" ends here, with the original untouched.
 *
 * @see docs/guide/controllers.md — "spec:make is the only way in"
 */
final class UnverifiedInsertionException extends RuntimeException implements SpecException
{
    public static function changedMoreThanTheExtension(string $identity): self
    {
        return new self(sprintf(
            'Adding `x-controller` to "%s" would have changed something else in the contract, so '.
            'nothing was written and your specification is untouched. Add the row by hand — and if '.
            'the document looks fine to you, this is worth reporting.',
            $identity
        ));
    }

    public static function unreadableAfterEditing(string $reason): self
    {
        return new self(sprintf(
            'The specification could not be read back after adding `x-controller`, so nothing was '.
            'written and your document is untouched. The reader said: %s',
            $reason
        ));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Cannot read %s, so nothing was written.', $path));
    }

    public static function unwritable(string $path): self
    {
        return new self(sprintf('Cannot write %s, so nothing was written.', $path));
    }
}
