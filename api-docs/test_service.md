# Service API recipes

Recipes for the HTTP(S) service endpoints. A service ties an agent and
a target to a URI (scheme, port, path, query) plus an F5-style
send/receive/disable assertion, and the agent reports what each poll
saw. Create, update, delete, and the reset require an administrator
token; the list is public, and the detail hides the probe-construction
config from anonymous callers.

The examples call the API on the local host; adjust the base URL to
match the deployment.

## Acquire a token

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password": "***"}' | jq -r '.token')
```

## List all services

The list endpoint is public and requires no token:

```bash
curl -s http://localhost/cgi-bin/api/services | jq '.'
```

The list supports optional query filters: `agent_id`, `target_id`,
`is_active` (0 or 1), and `q`. `is_active` means effectively active —
the service, its agent, and its target must all be enabled, so a
service under a paused agent does not answer to `is_active=1`. `q` is
a substring match over the description, the URI path, and the target
address:

```bash
curl -s 'http://localhost/cgi-bin/api/services?is_active=1&q=status' | jq '.'
```

## Resolve the ids used below

`agent_id` and `target_id` are required and must reference existing
records; the API returns 404 otherwise. Resolve the seeded LOCAL agent
and take a target id from the public list. Always resolve ids
dynamically like this: hardcoding row ids goes stale the moment the
database is reseeded.

```bash
AGENT_ID=$(curl -s http://localhost/cgi-bin/api/agents \
  | jq -r '.agents[] | select(.name=="LOCAL") | .id')
TARGET_ID=$(curl -s http://localhost/cgi-bin/api/targets | jq -r '.targets[0].id')
```

## Create a service

HTTP check with a receive assertion — the service is UP only while the
response body still contains the expected string:

```bash
curl -s -X POST http://localhost/cgi-bin/api/service \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"Example status page check\",
    \"scheme\": \"http\",
    \"port\": 80,
    \"uri_path\": \"/status\",
    \"receive_string\": \"ALL SYSTEMS OPERATIONAL\"
  }" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Service created successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

Omitted fields take their defaults: scheme http, port 0 (the scheme
default), uri_path /, http_method GET, pollcount 1, pollinterval 300,
timeout 10 seconds, verify_tls on, redirects unfollowed, and is_active
1. With no receive_string, any 2xx/3xx status keeps the service UP;
`expected_status` narrows that window.

## Create an HTTPS service with a disable assertion

`verify_tls` defaults to 1 — the certificate is verified; setting it
to 0 is allowed but recorded in the UI, never silent. A disable string
turns the check DOWN even when the receive string also matches (the F5
rule that catches a maintenance page containing healthy text):

```bash
curl -s -X POST http://localhost/cgi-bin/api/service \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"Example API health check\",
    \"scheme\": \"https\",
    \"port\": 443,
    \"uri_path\": \"/health\",
    \"expected_status\": \"200-299\",
    \"receive_string\": \"healthy\",
    \"disable_string\": \"MAINTENANCE\"
  }" | jq '.'
```

The response matches the create above.

## The send/receive/disable assertion model

Assertions follow the F5 model. Matching is substring, case-sensitive,
against the decoded response body — headers never participate. Setting
the paired `receive_regex` or `disable_regex` flag to 1 turns that
field into a Perl regex instead; an invalid regex is refused at save
time, and if one reaches the agent anyway the check goes DOWN with
`bad_regex` rather than crashing. `send_string`, when set, IS the
request body (F5 behavior), so it cannot be combined with `body`.

Precedence is evaluated in order, stopping at the first DOWN, and
disable always beats receive:

```
state = UP
if transport/TLS error            -> DOWN (transport_error | tls_error)
if timeout                        -> DOWN (timeout)
if status check fails             -> DOWN (status_mismatch)
if auth configured and 401/403    -> DOWN (auth_error)
if disable_string set and matches -> DOWN (disable_match)   # beats receive
elsif receive_string set:
     match                        -> UP   (receive_match)
     no match                     -> DOWN (receive_miss, empty_body)
elsif no receive_string and status ok
                                  -> UP   (status_ok)
```

### Reason tokens

Every result carries exactly one reason token from a closed set — the
UI maps all of them, so the set cannot grow casually:

- `status_ok` — UP: no receive assertion and the status check passed
- `receive_match` — UP: receive string matched the response body
- `receive_miss` — DOWN: receive string not found in the body
- `empty_body` — DOWN: the response carried no body, so a receive
  assertion cannot be evaluated
- `disable_match` — DOWN: disable string matched, even when the
  receive string also matched
- `status_mismatch` — DOWN: status outside `expected_status`, or
  outside 2xx/3xx when no expectation is configured
- `auth_error` — DOWN: configured authentication received 401/403, or
  the referenced credential could not be delivered — a hint to check
  the credential, distinct from a plain status mismatch
- `timeout` — DOWN: the request exceeded the configured timeout
- `transport_error` — DOWN: the request failed below HTTP (connection
  refused, unreachable host, unsupported scheme or method)
- `tls_error` — DOWN: TLS handshake or certificate failure
- `bad_regex` — DOWN: an assertion regex failed to compile at probe
  time
- `agent_unsupported` — DOWN: the check sits on an agent that has not
  self-declared service support, so nothing polls it

## Retrieve a service

Use a service id from the list response:

```bash
SERVICE_ID=$(curl -s http://localhost/cgi-bin/api/services | jq -r '.services[0].id')

curl -s http://localhost/cgi-bin/api/service/$SERVICE_ID | jq '.'
```

Anonymous callers receive a display-safe subset: identity, agent and
target names, the URI, the schedule and tolerance knobs, and the
rolled-up live state. The HTTP method, header map, request body,
assertion strings, and the auth reference never appear — they describe
how to construct a probe, not how a check is doing.

With a valid token the full configuration row comes back instead, the
same access the editor uses before saving. Even the full row carries
only the `auth_credential_id` reference — a service never stores the
secret itself; the credential follows the credentials feature's rules
(list responses never include it, detail exposes it to administrators
only).

```bash
curl -s http://localhost/cgi-bin/api/service/$SERVICE_ID \
  -H "Authorization: Bearer ***" | jq '.'
```

## Update a service

The description, URI, request options, assertion triple, auth
reference, and active flag can be changed after creation; keys left
out of the request are left alone:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/service/$SERVICE_ID \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated description"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Service updated successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Deactivate a service

```bash
curl -s -X PUT http://localhost/cgi-bin/api/service/$SERVICE_ID \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

The response matches the description update above.

## Polling parameters are immutable

`pollcount` and `pollinterval` are fixed when the service is created —
the RRD archives are sized from them. An update that touches either is
rejected outright with HTTP 400:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/service/$SERVICE_ID \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d '{
    "pollcount": 5,
    "pollinterval": 60
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Cannot modify polling parameters after service creation. Delete and recreate the service to change these values."
}
```

## Reset service state

The reset clears `total_down` and the current status columns and
stamps `last_change`; `last_check` is nulled on purpose, putting the
check straight back into the polling rotation:

```bash
curl -s -X POST http://localhost/cgi-bin/api/service/$SERVICE_ID/reset \
  -H "Authorization: Bearer ***" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Service statistics reset successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Delete a service

Deleting a service removes its configuration row and its RRD history
file. Deleting the service's agent or target takes the row too, via
the foreign-key cascade — but only an explicit service delete removes
its RRD file. The operation cannot be undone.

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/service/$SERVICE_ID \
  -H "Authorization: Bearer ***" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Service deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Error handling

### Invalid scheme

Use the create request shape above with `"scheme": "ftp"`:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid scheme (must be http or https)"
}
```

### Body without an encoding

Same request shape with `"http_method": "POST"` and a body but no
`body_encoding`:

```json
{
  "status": "error",
  "message": "Validation failed: body_encoding is required when body is set"
}
```

### send_string and body together

F5 semantics make `send_string` the request body, so setting both is a
config error caught at save time:

```json
{
  "status": "error",
  "message": "Validation failed: send_string and body cannot both be set"
}
```

### Reversed status range

A reversed `expected_status` range would match nothing, and the check
would sit DOWN forever — it is refused up front:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid expected_status token: 500-200 (range start must not exceed range end)"
}
```

### Duplicate service

A service is unique on the combination of agent, target, and the full
URI (scheme, port, path, and query). Repeating the status-page
creation from above — same agent and target, same port and path, since
the scheme still defaults to http — fails with HTTP 400:

```bash
curl -s -X POST http://localhost/cgi-bin/api/service \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"port\": 80,
    \"uri_path\": \"/status\"
  }" | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Service with these parameters already exists"
}
```

### Service not found

Requests that reference an unknown service id fail with HTTP 404.
The same error applies to the GET, PUT, and DELETE variants:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/service/NON-EXISTENT-ID \
  -H "Authorization: Bearer ***" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "No such service"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Service not found"
}
```