# Test: Agent APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

Agent routes need a user token, and mutations are admin-only. For the
non-admin checks at the bottom you also need a standard user — see
test_users.md.

## Get a single agent (with an admin token)

The agent password is only returned to admins. The seeded LOCAL agent
uses the well-known password `LOCAL`:

```bash
curl -s http://localhost/cgi-bin/api/agent/00000000-0000-0000-0000-000000000000 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "agent": {
    "id": "00000000-0000-0000-0000-000000000000",
    "name": "LOCAL",
    "address": "127.0.0.1",
    "description": "Local Agent",
    "last_seen": "2025-06-13 23:39:58",
    "is_active": 1,
    "password": "LOCAL"
  }
}
```

With a non-admin token the same request returns the agent without the
password field.

## Create a new agent

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

### Expected response:

```json
{
  "status": "success",
  "message": "Agent created successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

Only `name` is required. If `password` is omitted the agent gets the
placeholder password `CHANGE_ME` — set a real one before an agent
deploys against it.

## Update an existing agent (save the id from the create response)

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

### Expected response:

```json
{
  "status": "success",
  "message": "Agent updated successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Delete an agent

Deleting an agent takes its monitors with it (and their RRD files);
the response lists the monitor ids that were removed:

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/agent/12345678-1234-5678-1234-567812345678 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "message": "Agent and associated monitors deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678",
  "deleted_monitors": []
}
```

## Try to delete the LOCAL agent (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/agent/00000000-0000-0000-0000-000000000000 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Cannot delete LOCAL agent"
}
```

## Try to create an agent with an invalid IP (should fail)

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

### Expected response:

```json
{
  "status": "error",
  "message": "Invalid IP address"
}
```

## Try to create an agent with a duplicate name (should fail)

Agent names are unique. Reusing `LOCAL` (or any existing name) is
rejected with a clean message rather than a raw database error:

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

### Expected response:

```json
{
  "status": "error",
  "message": "Agent name already exists"
}
```

## Try a mutation with a non-admin token (should fail)

Create a standard user first (see test_users.md), then:

```bash
NON_ADMIN_TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"deskuser","password":"<its-password>"}' | jq -r '.token')

curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "NO-ACCESS-AGENT"}' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Admin required"
}
```