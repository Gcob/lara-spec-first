<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers;

/**
 * The templated counterpart of {@see ShowCurrentUserController}, so that the
 * fixture can prove document order decides which of two matching routes wins.
 */
class ShowUserController
{
    public function routeAction(string $id): string
    {
        return 'show-user:'.$id;
    }
}
