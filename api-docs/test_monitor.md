# Test: Monitor APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

Create, update, delete, and the statistics reset are admin-only.

## List all monitors (public endpoint)

```bash
curl -s http://localhost/cgi-bin/api/monitors | jq '.'
```

## Create a basic ICMP monitor

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "description": "Google DNS Monitor",
    "protocol": "ICMP",
    "dscp": "BE",
    "pollcount": 5,
    "pollinterval": 60
  }' | jq '.'
```

Defaults if you leave them out: protocol ICMP, port 0, dscp BE,
pollcount 5, pollinterval 60.

## Create a TCP monitor

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "description": "Web Server Monitor",
    "protocol": "TCP",
    "port": 443,
    "dscp": "AF21",
    "pollcount": 3,
    "pollinterval": 30
  }' | jq '.'
```

## Try to create a monitor with an invalid protocol (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "protocol": "INVALID"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid protocol"
}
```

## Try to create a monitor with an invalid DSCP (should fail)

Same shape as above with `"dscp": "INVALID"`. Expected response:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid DSCP value"
}
```

## Try to create a monitor with an invalid port (should fail)

Same shape as above with `"protocol": "TCP", "port": 99999`. Expected
response:

```json
{
  "status": "error",
  "message": "Validation failed: Port must be between 0 and 65535"
}
```

## Try to create a duplicate monitor (should fail)

A monitor is unique on agent, target, protocol, port, and DSCP
together. Repeat the exact ICMP/BE creation from the top — same agent
and target — and the second attempt fails:

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "protocol": "ICMP",
    "dscp": "BE"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Monitor with these parameters already exists"
}
```

## Get a single monitor (use a real id)

```bash
curl -s http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update the monitor description

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

## Deactivate the monitor

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

## Try to update polling parameters (should fail)

`pollcount` and `pollinterval` are fixed when the monitor is created —
an update that touches either is rejected outright:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pollcount": 10,
    "pollinterval": 120
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Cannot modify polling parameters after monitor creation. Delete and recreate the monitor to change these values."
}
```

## Reset the monitor statistics

Clears the counters and lifetime averages and stamps `last_clear`:

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404/reset \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "message": "Monitor statistics reset successfully",
  "id": "29234F4C-48B5-11F0-A00C-E37EC6BF7404"
}
```

## Try to update a non-existent monitor (should fail)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/NON-EXISTENT-ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "This should fail"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Monitor not found"
}
```

## Delete a monitor

Deleting a monitor also deletes its RRD data files. This cannot be
undone.

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "message": "Monitor deleted successfully",
  "id": "29234F4C-48B5-11F0-A00C-E37EC6BF7404"
}
```

## Try to delete a non-existent monitor (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/monitor/NON-EXISTENT-ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Monitor not found"
}
```