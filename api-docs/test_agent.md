# Agent API recipes

Recipes for the agent management endpoints. Every agent route requires
a bearer token, and the create, update, and delete operations are
restricted to administrators. The detail view is available to any
authenticated user, but the agent password is included in the response
only for administrators. The non-administrator checks below require a
standard user account — see test_users.md for how to create one.

The examples call the API on the local host; adjust the base URL to
match the deployment.

## Acquire a token

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## Retrieve an agent

The agent password is returned only to administrators. Agent ids are
assigned at install time, so resolve the id of the seeded LOCAL agent
from the public list endpoint first.

```bash
AGENT_ID=$(curl -s http://localhost/cgi-bin/api/agents \
  | jq -r '.agents[] | select(.name=="LOCAL") | .id')

curl -s http://localhost/cgi-bin/api/agent/$AGENT_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "agent": {
    "id": "5617F7CE-1906-11F1-9A2B-C7C16252CAB2",
    "name": "LOCAL",
    "address": "::1",
    "description": "Local Agent",
    "last_seen": "2026-09-11 00:44:01",
    "is_active": 1,
    "password": "LOCAL"
  }
}
```

The seeded LOCAL agent carries the well-known password `LOCAL`. With a
non-administrator token, the same request returns the agent without
the password field.

## Create an agent

```bash
curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "TEST-AGENT-1",
    "address": "192.168.1.100",
    "description": "Test Agent 1",
    "password": "<agent-password>",
    "is_active": true
  }' | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Agent created successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

Only `name` is required. When `password` is omitted, the agent is
assigned the placeholder password `CHANGE_ME`; set a real password
before an agent deploys against it.

## Update an agent

Use the id returned by the create call.

```bash
curl -s -X PUT http://localhost/cgi-bin/api/agent/12345678-1234-5678-1234-567812345678 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Test Agent 1",
    "address": "192.168.1.101",
    "password": "<new-agent-password>"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Agent updated successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Delete an agent

Deleting an agent also removes its monitors and their RRD files; the
response lists the ids of the monitors that were removed.

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/agent/12345678-1234-5678-1234-567812345678 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Agent and associated monitors deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678",
  "deleted_monitors": []
}
```

## Fetch the agent script source

The remote-agent install script itself is served to authenticated
callers as JSON — the same source the classic console downloads from
netping.php. Without a token the endpoint answers 401; a missing or
unreadable script answers 404:

```bash
curl -s http://localhost/cgi-bin/api/netping-script \
  -H "Authorization: Bearer ***" | jq -r '.filename'
```

### Expected response

```
netping-agent.pl
```

The full script body rides in the `content` field of the same response.

## Error handling

### Invalid IP address

Agent addresses must be valid IP addresses. The create request fails
with HTTP 400:

```bash
curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "BAD-IP-AGENT",
    "address": "300.300.300.300",
    "description": "Invalid IP Test"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Invalid IP address"
}
```

### Duplicate agent name

Agent names are unique. Reusing `LOCAL` — or any existing name — is
rejected with HTTP 400 and a clean message rather than a raw database
error:

```bash
curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "LOCAL",
    "address": "192.168.1.1",
    "description": "Duplicate Name Test"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Agent name already exists"
}
```

### LOCAL agent protection

The seeded LOCAL agent cannot be deleted. The request fails with
HTTP 403:

```bash
AGENT_ID=$(curl -s http://localhost/cgi-bin/api/agents \
  | jq -r '.agents[] | select(.name=="LOCAL") | .id')

curl -s -X DELETE http://localhost/cgi-bin/api/agent/$AGENT_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Cannot delete LOCAL agent"
}
```

### Non-administrator mutations

Mutations issued with a non-administrator token are rejected with
HTTP 403. Create a standard user first (see test_users.md), then:

```bash
NON_ADMIN_TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"deskuser","password":"<its-password>"}' | jq -r '.token')

curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "NO-ACCESS-AGENT"}' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Admin required"
}
```