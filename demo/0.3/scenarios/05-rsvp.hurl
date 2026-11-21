# Run after the folder's code is copied in: `createRsvp` and `listRsvps`. The
# contract declares a 409 for a full event. It states the rule, and this
# folder's controller is what keeps it: nothing in the package counts RSVPs.

# An event with room for one person.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Quebec City board games",
  "city": "Quebec City",
  "starts_at": "2026-12-10T18:30:00Z",
  "capacity": 1
}
HTTP 201
[Captures]
event_id: jsonpath "$.id"

# The first RSVP fits.
POST {{host}}/events/{{event_id}}/rsvps
Accept: application/json
Content-Type: application/json
{
  "name": "Ada",
  "email": "ada@example.com"
}
HTTP 201
[Asserts]
jsonpath "$.name" == "Ada"

# Both fields are required: leaving the email out is a 422 on that field.
POST {{host}}/events/{{event_id}}/rsvps
Accept: application/json
Content-Type: application/json
{
  "name": "Grace"
}
HTTP 422
[Asserts]
jsonpath "$.errors.email" exists

# `email` has a format: a string that is not an address is a 422 too.
POST {{host}}/events/{{event_id}}/rsvps
Accept: application/json
Content-Type: application/json
{
  "name": "Grace",
  "email": "not-an-email"
}
HTTP 422
[Asserts]
jsonpath "$.errors.email" exists

# The event is full now. The body is good, so the request passes, and the
# controller refuses: the contract declares the 409, the code enforces it.
POST {{host}}/events/{{event_id}}/rsvps
Accept: application/json
Content-Type: application/json
{
  "name": "Grace",
  "email": "grace@example.com"
}
HTTP 409
[Asserts]
jsonpath "$.message" == "The event is full."

# Only the first RSVP was kept.
GET {{host}}/events/{{event_id}}/rsvps
Accept: application/json
HTTP 200
[Asserts]
jsonpath "$" count == 1
jsonpath "$[0].email" == "ada@example.com"
jsonpath "$[0].*" count == 3
jsonpath "$[0].event_id" not exists

# An event nobody created has no RSVPs to list and none to take, a 404 from the
# code in both cases.
GET {{host}}/events/999999/rsvps
Accept: application/json
HTTP 404

POST {{host}}/events/999999/rsvps
Accept: application/json
Content-Type: application/json
{
  "name": "Ada",
  "email": "ada@example.com"
}
HTTP 404
