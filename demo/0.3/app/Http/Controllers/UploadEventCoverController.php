<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Data\CoverDto;
use App\Http\Generated\Requests\UploadEventCoverRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class UploadEventCoverController extends \App\Http\Generated\Controllers\UploadEventCoverController
{
    public function routeAction(UploadEventCoverRequest $request, string $event): CoverDto
    {
        $event = Event::findOrFail($event);

        // The request has already checked that `cover` is a PNG or a JPEG of at most 2 MB. Where it goes is this code's
        // choice: the package never stores a file.
        $path = $request->file('cover')->store('covers');

        return new CoverDto(id: $event->id, cover: $path);
    }
}
