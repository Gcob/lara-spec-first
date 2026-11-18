# Run after the folder's code is copied in: `uploadEventCover` takes a
# multipart body with one file part, `cover`. The contract says it is a PNG or a
# JPEG of at most 2 MB, and the generated request checks that before the code
# stores anything.

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

# A PNG is accepted and stored.
POST {{host}}/events/{{event_id}}/cover
Accept: application/json
[MultipartFormData]
cover: file,files/cover.png; image/png
HTTP 200
[Asserts]
jsonpath "$.id" == {{event_id}}
jsonpath "$.cover" exists

# A text file is not a PNG or a JPEG: a 422 on `cover`.
POST {{host}}/events/{{event_id}}/cover
Accept: application/json
[MultipartFormData]
cover: file,files/notes.txt; text/plain
HTTP 422
[Asserts]
jsonpath "$.errors.cover" exists

# `cover` is required: a form with other parts and no `cover` is a 422 too.
POST {{host}}/events/{{event_id}}/cover
Accept: application/json
[MultipartFormData]
caption: no file in this one
HTTP 422
[Asserts]
jsonpath "$.errors.cover" exists

# A good file for an event nobody created is a 404, from the code.
POST {{host}}/events/999999/cover
Accept: application/json
[MultipartFormData]
cover: file,files/cover.png; image/png
HTTP 404
