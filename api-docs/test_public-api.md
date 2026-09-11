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

`/monitors` is the only listing that supports filters, which are
passed as query parameters:

```bash
# Monitors currently at exactly 0% loss
curl -s "http://localhost/cgi-bin/api/monitors?current_loss=0" | jq '.'

# Only effectively active monitors
curl -s "http://localhost/cgi-bin/api/monitors?is_active=1" | jq '.'
```

`/agents` and `/targets` accept no filters.