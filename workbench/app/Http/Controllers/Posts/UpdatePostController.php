<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers\Posts;

use Gcob\LaraSpecFirst\Data\Optional;
use Workbench\App\Http\Generated\Requests\UpdatePostRequest;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
// If it still fails, the specification no longer has an `x-controller` pointing here.
class UpdatePostController extends \Workbench\App\Http\Generated\Controllers\UpdatePostController
{
    public function routeAction(UpdatePostRequest $request, string $postId): mixed
    {
        // The partial type: every property may be absent, and absent is not
        // `null`. `toArray()` is what `$post->update()` would receive, so a
        // column the client did not mention is left alone.
        $post = $request->dto();

        return [
            'postId' => $postId,
            'update' => $post->toArray(),
            // `summary` is nullable, so the three states are all reachable: not
            // sent, sent as null on purpose, and sent with a value.
            'summary' => match (true) {
                $post->summary instanceof Optional => 'not sent',
                $post->summary === null => 'sent as null',
                default => 'sent as a value',
            },
        ];
    }
}
