# Demo 0.2

The demo of the `0.2` release: an events and RSVPs API, built from an OpenAPI contract and the code a developer writes
on top of what `lara-spec-first` `0.2.*` generates. This folder is frozen: `0.2.0` is released. It describes the package
as it was then, and it runs against `0.2.*` from Packagist. How to run it, and why there is one folder per minor, is in
[the demo's README](../README.md).

```bash
demo/run.sh 0.2
```

## What 0.2 shows

Six operations, each with its `x-controller`. [`openapi.yaml`](./openapi.yaml) is the contract, copied to the app as it
is. The scenarios are in [`scenarios/`](./scenarios), and every request sends `Accept: application/json`, since a failed
validation answers a redirect without it
([why](https://github.com/Gcob/lara-spec-first/blob/v0.2.0/docs/guide/code-generation/request-validation.md#what-a-client-gets-when-a-request-is-refused)).

- **`01-before-the-code.hurl`:** `spec:build` and `spec:make --all --yes` have run, and nobody has written a controller.
  A good request to each of the six operations reaches the generated controller and gets a `501` that names the
  operation. A body without `title` never gets there: it is a `422` on `title`. The contract alone already answers.
- **`02-create-an-event.hurl`:** `createEvent` stores an event and `getEvent` reads it back. A missing `title`, a
  `capacity` of `"many"` and a `capacity` of `"3"` are each a `422` on their field, because a JSON body keeps its types.
  An id nobody created is a `404`, from the controller.
- **`03-find-events.hurl`:** `listEvents` takes `city` and `from` as plain query parameters. The code reads them with
  `validated('city')`. `from=yesterday` is a `422` on `from`.
- **`04-upload-a-cover.hurl`:** `uploadEventCover` takes a `multipart/form-data` body with one file part. A PNG is
  stored. A text file, or a form with no `cover`, is a `422` on `cover`. A file named `*.php` is refused even when it
  says it is a PNG, which is Laravel's own rule, stated in the generated request. The package stores nothing: the
  controller does.
- **`05-rsvp.hurl`:** `createRsvp` and `listRsvps`. A missing or malformed `email` is a `422`. The contract declares a
  `409` for a full event, and the controller is what enforces it: the contract states the rule, the code keeps it.

The code is in [`app/`](./app) and [`database/`](./database): an Eloquent model and a migration per table, and a
controller per operation that replaces the scaffold `spec:make` wrote.

## What it does not do yet

Each of these is outside `0.2.0`, so this folder neither shows it nor asserts it.

- **`filter[city]`:** a `deepObject` query parameter is not validated yet
  ([#139](https://github.com/Gcob/lara-spec-first/issues/139)), so the list filters with plain `city` and `from`.
- **A `404` from the contract:** before the code, `GET /events/abc` answers `501`. The `404` for an event that does not
  exist is the controller's, since the model layer that would give it comes with `x-model` (Lot 3,
  [#95](https://github.com/Gcob/lara-spec-first/issues/95)).
- **Response DTOs:** the controllers return models, and nothing checks an answer against the contract (Lot 2,
  [#94](https://github.com/Gcob/lara-spec-first/issues/94)).
- **An integral float for an integer:** `"capacity": 2.0` is a `422`, where a JSON Schema reads `2.0` as an integer
  ([#173](https://github.com/Gcob/lara-spec-first/issues/173)).
- **`security`:** organizer and member scopes are not in the contract. The package reports `security` and does not
  enforce it yet (Lot 4, [#96](https://github.com/Gcob/lara-spec-first/issues/96)).
- **Mocked answers:** nothing answers from the contract's examples or from Faker (Lot 5,
  [#97](https://github.com/Gcob/lara-spec-first/issues/97)).
- **Pagination and rate limiting:** the list returns every match, and the RSVP endpoint is not limited (Lot 6,
  [#98](https://github.com/Gcob/lara-spec-first/issues/98)).

## What `spec:doctor` reports

The run builds the contract, then runs `spec:doctor` and compares its exit code with [`doctor-exit`](./doctor-exit),
which holds `0`. On this contract the doctor reports:

- every section clean: document validity, references, routing (the six routes and their controllers), security, drift,
  installation;
- one `[deferred]` line, `15 response declaration(s) found`, a fact about the roadmap and not a defect in the contract:
  response DTOs are not built in `0.2.0`;
- the lifecycle section listing the six operations as `beta`, since none declares a `stable` status;
- `[not checked]` for the baseline and the drivers, which `0.2.0` does not diagnose yet.

It ends with `Clean. 1 deferred item(s) above are a fact about this package's roadmap, not a reason to fail.`, and exits
`0`. A release that makes the doctor report a fault or a limit on this contract fails the run. See
[the doctor's guide](https://github.com/Gcob/lara-spec-first/blob/v0.2.0/docs/guide/doctor.md) for the exit codes.

## What 0.2.0 gets wrong

Nothing found by this demo. Every case it exercises answers what the contract says.
