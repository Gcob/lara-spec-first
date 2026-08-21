<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Users;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
// If it still fails, the specification no longer has an `x-controller` pointing here.
class ShowUserController extends \Workbench\App\Http\Generated\Controllers\ShowUserController
{
    /**
     * The one operation in this contract that answers for real.
     *
     * `spec:make` wrote this class with `return parent::routeAction($id);` in it,
     * which answers 501 — and replacing that line is what implementing an operation
     * means. Every other operation here still has the line, so the pair is visible
     * side by side: `GET /users/42` answers, `GET /posts` does not, and the
     * difference is one line in a file the developer owns.
     */
    public function routeAction(string $id): mixed
    {
        return ['id' => $id, 'answered_by' => self::class];
    }
}
