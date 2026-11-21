# Demo 0.3

The demo of the `0.3` release: an events and RSVPs API, built from an OpenAPI contract and the code a developer writes
on top of what `lara-spec-first` `0.3.*` generates. This folder is frozen once `0.3.0` is tagged: it then describes the
package as it was, and it runs against `0.3.*` from Packagist. Until the tag it runs against a branch. How to run it, and
why there is one folder per minor, is in [the demo's README](../README.md).

```bash
demo/run.sh 0.3 <branch>   # before 0.3.0 is tagged
demo/run.sh 0.3            # after
```

## What 0.3 shows

The same six operations as [the 0.2 demo](../0.2), each with its `x-controller`. [`openapi.yaml`](./openapi.yaml) is the
contract, copied to the app as it is, and only its version changed. The scenarios are in [`scenarios/`](./scenarios), and
every request sends `Accept: application/json`, since a failed validation answers a redirect without it
([why](https://github.com/Gcob/lara-spec-first/blob/v0.3.0/docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).

What is new is in the controllers: each one returns the response DTO of its operation, and nothing else.

- **`01-before-the-code.hurl`:** `spec:build` and `spec:make --all --yes` have run, and nobody has written a controller.
  A good request to each of the six operations reaches the generated controller and gets a `501` that names the
  operation. A body without `title` never gets there: it is a `422` on `title`. The contract alone already answers.
- **`02-create-an-event.hurl`:** `createEvent` stores an event and `getEvent` reads it back. An answer has exactly the
  contract's five fields and no `created_at`, since the DTO is the shape of the answer and the model is not. The `201`
  is the contract's: the generated parent sends it, and the controller never writes it. A `starts_at` sent with an
  offset is stored as the same instant, with no conversion in the controller, because the request's DTO already holds it
  in the app's timezone
  ([why](https://github.com/Gcob/lara-spec-first/blob/v0.3.0/docs/project/decisions/0025-set-a-request-date-time-to-the-app-timezone.md)).
  A missing `title`, a `capacity` of `"many"` and a `capacity` of `"3"` are each a `422` on their field, because a JSON
  body keeps its types. A `capacity` of `40.0` is a whole number, so it is accepted and answers `40`, and `2.5` is a
  `422`. An id nobody created is a `404`, from the controller.
- **`03-find-events.hurl`:** `listEvents` takes `city` and `from` as plain query parameters. The code reads them with
  `validated('city')`. `from=yesterday` is a `422` on `from`. A `starts_at` sent with an offset is the same instant as
  its UTC time, so the filter and the answer agree on the day.
- **`04-upload-a-cover.hurl`:** `uploadEventCover` takes a `multipart/form-data` body with one file part. A PNG is
  stored and the answer is a `CoverDto`. A text file, or a form with no `cover`, is a `422` on `cover`. A file with a PHP
  extension is refused even when it says it is a PNG, which is Laravel's own rule, stated in the generated request. The
  package stores nothing: the controller does.
- **`05-rsvp.hurl`:** `createRsvp` and `listRsvps`. A missing or malformed `email` is a `422`. The contract declares a
  `409` for a full event, and the controller is what enforces it, by throwing it: the contract states the rule, the code
  keeps it, and the message is `The event is full.`. The `201` of a new RSVP is the parent's too, and a listed RSVP has
  its three fields, with no `event_id`.

The code is in [`app/`](./app) and [`database/`](./database): an Eloquent model and a migration per table, and a
controller per operation that replaces the scaffold `spec:make` wrote.

## From 0.2 to 0.3

The contract is the same, so the scenarios that applied still pass. What changed is the code a developer writes, because
`routeAction` now returns what the contract's success status carries
([the signature is the contract](https://github.com/Gcob/lara-spec-first/blob/v0.3.0/docs/guide/controllers.md#the-signature-is-the-contract)).

- **The return type:** `EventDto`, `RsvpDto` or `CoverDto` for one item, and `array` with `@return list<EventDto>` for a
  list, where 0.2 wrote `mixed`. Each controller builds its DTO with the generated `from()`
  (`EventDto::from($event->toArray())`), or with `new CoverDto(...)` where no model holds the data.
- **No `response()->json(..., 201)`:** the generated parent sends the contract's `201`, so `createEvent` is
  `Event::create($request->dto()->toArray())` and a return.
- **The `409` is thrown:** `abort_if(..., 409, 'The event is full.')` replaces a returned `response()->json([...], 409)`.
  The message is the same one 0.2 sent. The body is not exactly the same: the demo's app runs in debug mode, where
  Laravel adds a trace to a thrown error.
- **No `$hidden`:** 0.2 hid `created_at`, `updated_at` and `event_id` on the models. The DTO now decides the fields of an
  answer, so the models say nothing about it.
- **No UTC conversion:** 0.2's `createEvent` converted `starts_at` to UTC by hand. The request's DTO now holds it in the
  app's timezone, and the model stores it as it is.
- **The scenarios:** 02 gains the `$.*` count, the absent `created_at`, and the `40.0` and `2.5` requests. 05 gains the
  `409` message and the `$[0].*` count. The other three are 0.2's, unchanged.

An app that upgrades from 0.2 gets a hint: `spec:build` names every custom controller whose `routeAction` fell behind its
parent, with the signature to paste, and the build still succeeds. This demo never prints it, since its controllers are
copied after the build. The same section of the guide says what it prints.

## What it does not do yet

Each of these is outside `0.3.0`, so this folder neither shows it nor asserts it.

- **`filter[city]`:** the list still filters with plain `city` and `from`.
- **A `404` from the contract:** before the code, `GET /events/abc` answers `501`. The `404` for an event that does not
  exist is the controller's, since the model layer that would give it comes with `x-model` (Lot 3,
  [#95](https://github.com/Gcob/lara-spec-first/issues/95)).
- **`security`:** organizer and member scopes are not in the contract. The package reports `security` and does not
  enforce it yet (Lot 4, [#96](https://github.com/Gcob/lara-spec-first/issues/96)).
- **Mocked answers:** nothing answers from the contract's examples or from Faker (Lot 5,
  [#97](https://github.com/Gcob/lara-spec-first/issues/97)).
- **Pagination and rate limiting:** the list returns every match, and the RSVP endpoint is not limited (Lot 6,
  [#98](https://github.com/Gcob/lara-spec-first/issues/98)).

## What `spec:doctor` reports

The run builds the contract, then runs `spec:doctor` and compares its exit code with [`doctor-exit`](./doctor-exit),
which holds `0`. On this contract the doctor reports:

- every section clean: document validity, references, support findings, security, drift, installation;
- the routing outcome, the six routes and their controllers;
- no `[deferred]` line, since every response now has a DTO: 0.2 reported `15 response declaration(s) found`;
- the lifecycle section listing the six operations as `beta`, since none declares a `stable` status;
- `[not checked]` for the baseline and the drivers, which `0.3.0` does not diagnose yet.

It ends with `Clean. Nothing here is being dropped without a decision behind it.`, and exits `0`. A release that makes
the doctor report a fault or a limit on this contract fails the run. See
[the doctor's guide](https://github.com/Gcob/lara-spec-first/blob/v0.3.0/docs/guide/doctor.md) for the exit codes.

## What 0.3.0 gets wrong

Nothing found by this demo. Every case it exercises answers what the contract says.
