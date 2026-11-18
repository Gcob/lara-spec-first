<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Requests\ListEventsRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class ListEventsController extends \App\Http\Generated\Controllers\ListEventsController
{
    public function routeAction(ListEventsRequest $request): mixed
    {
        // A query parameter is not part of the DTO: it is read with `validated('name')`.
        $city = $request->validated('city');
        $from = $request->validated('from');

        return Event::query()
            ->when($city !== null, fn ($query) => $query->where('city', $city))
            ->when($from !== null, fn ($query) => $query->where('starts_at', '>=', $from.' 00:00:00'))
            ->orderBy('starts_at')
            ->get();
    }
}
