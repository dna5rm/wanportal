# Test: Public APIs

These endpoints require no authentication. They expose the monitoring
topology read-only; agent passwords never appear in any response.

## List all agents

```bash
curl -s http://localhost/cgi-bin/api/agents | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "agents": [
    {
      "id": "00000000-0000-0000-0000-000000000000",
      "name": "LOCAL",
      "address": "127.0.0.1",
      "description": "Local Agent",
      "last_seen": "2025-06-13 23:39:58",
      "is_active": 1
    }
  ]
}
```

## List all targets

```bash
curl -s http://localhost/cgi-bin/api/targets | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "targets": [
    {
      "id": "12345678-1234-5678-1234-567812345678",
      "address": "8.8.8.8",
      "description": "Google DNS",
      "is_active": 1
    }
  ]
}
```

## List all monitors

```bash
curl -s http://localhost/cgi-bin/api/monitors | jq '.'
```

### Expected response

One entry per monitor. The `is_active` flag is the effective value: a
monitor counts as inactive when its agent or its target is also
inactive.

```json
{
  "status": "success",
  "monitors": [
    {
      "id": "87654321-4321-8765-4321-876543210000",
      "description": "Google DNS Monitor",
      "agent_id": "00000000-0000-0000-0000-000000000000",
      "target_id": "12345678-1234-5678-1234-567812345678",
      "protocol": "ICMP",
      "port": 0,
      "dscp": "BE",
      "pollcount": 5,
      "pollinterval": 60,
      "is_active": 1,
      "sample": 120,
      "current_loss": 0,
      "current_median": 15.5,
      "current_min": 14.2,
      "current_max": 18.9,
      "current_stddev": 1.1,
      "avg_loss": 0,
      "avg_median": 14.8,
      "avg_min": 13.7,
      "avg_max": 19.4,
      "avg_stddev": 1.3,
      "prev_loss": 0,
      "last_clear": "2025-06-13 20:00:00",
      "last_down": "2025-06-01 04:12:00",
      "last_update": "2025-06-13 23:39:58",
      "total_down": 2,
      "agent_name": "LOCAL",
      "agent_is_active": 1,
      "target_address": "8.8.8.8",
      "target_is_active": 1
    }
  ]
}
```

## Filter monitors

`/monitors` and `/services` are the listings that support filters,
which are passed as query parameters:

```bash
# Monitors currently at exactly 0% loss
curl -s "http://localhost/cgi-bin/api/monitors?current_loss=0" | jq '.'

# Only effectively active monitors
curl -s "http://localhost/cgi-bin/api/monitors?is_active=1" | jq '.'
```

`/agents` and `/targets` accept no filters.

## List all services

```bash
curl -s http://localhost/cgi-bin/api/services | jq '.'
```

The public service listing shows the URI triple and the rolled-up live
state for each HTTP(S) check, plus the agent and target names. Like
the monitors listing, `is_active` is the effective value: a service
counts as inactive when its agent or its target is also inactive. For
parity with the monitor rows the joined agent/target liveness aliases
(`agent_is_active`, `target_is_active`) and the agent's services
capability bit (`agent_supports_services`) ride along, so a client
can name which side is disabled. No
auth field, assertion string, header, request body or HTTP method ever
appears — the row carries the state, not the probe recipe.

### Expected response

```json
{
  "status": "success",
  "services": [
    {
      "id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
      "description": "Example web check",
      "agent_id": "00000000-0000-0000-0000-000000000000",
      "target_id": "12345678-1234-5678-1234-567812345678",
      "scheme": "https",
      "port": 443,
      "uri_path": "/health",
      "uri_query": "",
      "is_active": 1,
      "last_state": "UP",
      "last_status_code": 200,
      "last_reason": "status_ok",
      "last_check": "2025-06-13 23:40:00",
      "last_change": "2025-06-10 08:12:31",
      "total_down": 0,
      "agent_name": "LOCAL",
      "agent_is_active": 1,
      "agent_supports_services": 1,
      "target_address": "www.example.com",
      "target_is_active": 1
    }
  ]
}
```

### Filters

`/services` takes `agent_id`, `target_id`, `is_active` and a free-text
`q` (matched against description, path and the target address), the
same query-parameter style as `/monitors`:

```bash
curl -s "http://localhost/cgi-bin/api/services?is_active=0" | jq '.'
curl -s "http://localhost/cgi-bin/api/services?q=example" | jq '.'
```

## Get service details

```bash
curl -s http://localhost/cgi-bin/api/service/:id | jq '.'
```

Anonymous callers get the display-safe subset of the row (identity,
URI, schedule and tolerance knobs, state): the assertion strings,
headers, request body and auth fields are stripped. With a valid
bearer token the full config row returns — still never a secret value,
because a service holds only the `auth_credential_id` reference into
the credentials vault.

## List the API documentation files

`GET /docs` is the public catalog of the operator guides: every `*.md`
in the docs directory, sorted by name, each entry carrying the title
taken from the file's first `# ` heading.

```bash
curl -s http://localhost/cgi-bin/api/docs | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "files": [
    {
      "name": "agent-image.md",
      "title": "Agent image"
    },
    {
      "name": "test_users.md",
      "title": "Test: Users API"
    }
  ]
}
```