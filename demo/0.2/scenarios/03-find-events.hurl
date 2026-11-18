# Run after the folder's code is copied in: `listEvents` takes two optional
# query parameters, `city` and `from`. A query parameter is checked like a body
# field, and the code reads it with `validated('name')`.

# Two events, a city and a date apart.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Gatineau PHP night",
  "city": "Gatineau",
  "starts_at": "2026-12-05T19:00:00Z",
  "capacity": 30
}
HTTP 201

POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Sherbrooke Laravel day",
  "city": "Sherbrooke",
  "starts_at": "2027-03-15T09:00:00Z",
  "capacity": 80
}
HTTP 201

# `city` keeps the events of that city.
GET {{host}}/events?city=Gatineau
Accept: application/json
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Gatineau PHP night')]" exists
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" not exists

# `from` keeps the events that start on that day or later.
GET {{host}}/events?from=2027-03-01
Accept: application/json
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" exists
jsonpath "$[?(@.title == 'Gatineau PHP night')]" not exists

# `from` is a date: a word is a 422 on that parameter, and the code never runs.
GET {{host}}/events?from=yesterday
Accept: application/json
HTTP 422
[Asserts]
jsonpath "$.errors.from" exists
