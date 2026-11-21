<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Data\EventDto;
use App\Http\Generated\Requests\ListEventsRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class ListEventsController extends \App\Http\Generated\Controllers\ListEventsController
{
    /**
     * @return list<EventDto>
     */
    public function routeAction(ListEventsRequest $request): array
    {
        // A query parameter is not part of the DTO: `filter` is read with `validated()`, nested as the client wrote it.
        $city = $request->validated('filter.city');
        $from = $request->validated('filter.from');

        return Event::query()
            ->when($city !== null, fn ($query) => $query->where('city', $city))
            ->when($from !== null, fn ($query) => $query->where('starts_at', '>=', $from.' 00:00:00'))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event) => EventDto::from($event->toArray()))
            ->all();
    }
}
