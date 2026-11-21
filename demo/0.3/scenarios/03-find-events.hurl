# Run after the folder's code is copied in: `listEvents` takes an optional
# `filter` object, with `filter[city]` and `filter[from]` inside. A query
# parameter is checked like a body field, and the code reads it with
# `validated('filter.city')`.

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

# No filter: every event.
GET {{host}}/events
Accept: application/json
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Gatineau PHP night')]" exists
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" exists

# A plain `city` is not the declared `filter[city]`: the code never sees it, and
# the answer is every event.
GET {{host}}/events
Accept: application/json
[Query]
city: Gatineau
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" exists

# `filter[city]` keeps the events of that city.
GET {{host}}/events
Accept: application/json
[Query]
filter[city]: Gatineau
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Gatineau PHP night')]" exists
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" not exists

# `filter[from]` keeps the events that start on that day or later.
GET {{host}}/events
Accept: application/json
[Query]
filter[from]: 2027-03-01
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Sherbrooke Laravel day')]" exists
jsonpath "$[?(@.title == 'Gatineau PHP night')]" not exists

# `filter[from]` compares days in UTC, and `starts_at` is an instant: 22:00 on
# December 1st at -05:00 is already December 2nd at Z, so
# `filter[from]=2026-12-02` keeps it.
POST {{host}}/events
Accept: application/json
Content-Type: application/json
{
  "title": "Trois-Rivieres late night",
  "city": "Trois-Rivieres",
  "starts_at": "2026-12-01T22:00:00-05:00",
  "capacity": 20
}
HTTP 201

GET {{host}}/events
Accept: application/json
[Query]
filter[from]: 2026-12-02
filter[city]: Trois-Rivieres
HTTP 200
[Asserts]
jsonpath "$[?(@.title == 'Trois-Rivieres late night')]" exists

# `filter[from]` is a date: a word is a 422 on that parameter, and the code never
# runs.
GET {{host}}/events
Accept: application/json
[Query]
filter[from]: yesterday
HTTP 422
[Asserts]
jsonpath "$.errors['filter.from']" exists

# The contract closes `filter`: a key it does not declare is a 422 on `filter`.
GET {{host}}/events
Accept: application/json
[Query]
filter[venue]: Olympic Stadium
HTTP 422
[Asserts]
jsonpath "$.errors.filter" exists
jsonpath "$.message" == "The filter field must be an array."
