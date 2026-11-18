# Run after `spec:make` and before any application code exists: the contract
# alone already answers, and checks every request it receives.

# Every request sends `Accept: application/json`. Without it Laravel answers a
# failed validation with a redirect to the previous page, not with a 422.

# A good body passes the generated request, reaches the generated controller,
# which nobody has implemented yet, and gets a 501 that names the operation.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Montreal PHP meetup",
  "city": "Montreal",
  "starts_at": "2026-12-01T19:00:00Z",
  "capacity": 40
}
HTTP 501
[Asserts]
body contains "createEvent"

# A body that breaks the contract never reaches a controller: it is refused
# with a 422 and the field named, before a line of code exists.
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
