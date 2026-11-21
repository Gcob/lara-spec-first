# Run after the folder's code is copied in: `createEvent` stores an event and
# `getEvent` reads it back. The request class still refuses what the contract
# refuses, and the code adds nothing to that.

# A good body is stored, and the answer carries the id it was given.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Montreal PHP meetup",
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00Z",
  "capacity": 40
}
HTTP 201
[Captures]
event_id: jsonpath "$.id"
[Asserts]
jsonpath "$.title" == "Montreal PHP meetup"
jsonpath "$.capacity" == 40
jsonpath "$.*" count == 5
jsonpath "$.created_at" not exists

# `starts_at` is an instant: a time sent with an offset is the same moment as
# its UTC time, and the answer carries it as UTC. 19:00 at -05:00 is midnight at
# Z, the next day.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Montreal PHP evening",
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00-05:00",
  "capacity": 40
}
HTTP 201
[Asserts]
jsonpath "$.starts_at" == "2026-12-02T00:00:00.000000Z"

# The title is required: leaving it out is a 422 on that field.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00Z",
  "capacity": 40
}
HTTP 422
[Asserts]
jsonpath "$.errors.title" exists

# `capacity` is an integer: a string where it goes is a 422.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Montreal PHP meetup",
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00Z",
  "capacity": "many"
}
HTTP 422
[Asserts]
jsonpath "$.errors.capacity" exists

# A JSON body keeps its types: "3" is a string, so it is refused too,
# where a form field would have been coerced.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Montreal PHP meetup",
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00Z",
  "capacity": "3"
}
HTTP 422
[Asserts]
jsonpath "$.errors.capacity" exists

# A whole number written as a float is an integer, as JSON Schema reads it.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Laval PHP meetup",
  "city": "Laval",
  "starts_at": "2026-12-03T19:00:00Z",
  "capacity": 40.0
}
HTTP 201
[Asserts]
jsonpath "$.capacity" == 40

# A fraction is not.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Laval PHP meetup",
  "city": "Laval",
  "starts_at": "2026-12-03T19:00:00Z",
  "capacity": 2.5
}
HTTP 422
[Asserts]
jsonpath "$.errors.capacity" exists

# The event just created is read back from its id.
GET {{host}}/events/{{event_id}}
Accept: application/json
HTTP 200
[Asserts]
jsonpath "$.title" == "Montreal PHP meetup"
jsonpath "$.*" count == 5

# An id nobody created is a 404. 0.3.0 does not check a path parameter, so this
# answer is the controller's, not the contract's.
GET {{host}}/events/999999
Accept: application/json
HTTP 404
