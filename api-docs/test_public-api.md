# Test: Public APIs

## List all agents (without passwords)

```bash
curl -s -X GET http://localhost/cgi-bin/api/agents | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "agents": [
    {
      "id": "00000000-0000-0000-0000-000000000000",
      "name": "LOCAL",
      "address": "127.0.0.1",
      "description": "Local Agent",
      "last_seen": "2024-01-01 00:00:00",
      "is_active": 1,
      "created_at": "2024-01-01 00:00:00",
      "created_by": "system",
      "updated_at": null,
      "updated_by": null
    },
    // ... other agents ...
  ]
}
```

## List all targets

```bash
curl -s -X GET http://localhost/cgi-bin/api/targets | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "targets": [
    {
      "id": "12345678-1234-5678-1234-567812345678",
      "address": "8.8.8.8",
      "description": "Google DNS",
      "is_active": 1,
      "created_at": "2024-01-01 00:00:00",
      "created_by": "admin",
      "updated_at": null,
      "updated_by": null
    },
    // ... other targets ...
  ]
}
```

## List all monitors

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitors | jq '.'
```

### Expected response:

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
      "is_active": 1,
      "current_loss": 0,
      "current_median": 15.5,
      "avg_loss": 0,
      "avg_median": 14.8,
      "last_update": "2024-01-01 00:00:00",
      "created_at": "2024-01-01 00:00:00",
      "updated_at": null,
      "agent_name": "LOCAL",
      "target_address": "8.8.8.8"
    },
    // ... other monitors ...
  ]
}
```

## Filter agents by status

```bash
curl -s -X GET http://localhost/cgi-bin/api/agents \
  -H "Content-Type: application/json" \
  -d '{"is_active": true}' | jq '.'
```

## Filter targets by address pattern

```bash
curl -s -X GET http://localhost/cgi-bin/api/targets \
  -H "Content-Type: application/json" \
  -d '{"address_like": "8.8.%"}' | jq '.'
```

## Filter monitors by agent

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitors \
  -H "Content-Type: application/json" \
  -d '{"agent_id": "00000000-0000-0000-0000-000000000000"}' | jq '.'
```

## Filter monitors by target

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitors \
  -H "Content-Type: application/json" \
  -d '{"target_id": "12345678-1234-5678-1234-567812345678"}' | jq '.'
```

## Filter monitors by protocol

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitors \
  -H "Content-Type: application/json" \
  -d '{"protocol": "ICMP"}' | jq '.'
```
