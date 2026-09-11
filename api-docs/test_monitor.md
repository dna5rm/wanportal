# Monitor API recipes

Recipes for the monitor configuration endpoints. A monitor ties an
agent to a target and defines how that agent probes the target.
Create, update, delete, and the statistics reset require an
administrator token; the monitor list is public.

The examples call the API on the local host; adjust the base URL to
match the deployment.

## Acquire a token

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## List all monitors

The list endpoint is public and requires no token:

```bash
curl -s http://localhost/cgi-bin/api/monitors | jq '.'
```

The list supports optional query filters, for example `is_active`
(0 or 1) and `current_loss` (exact percentage match).

## Resolve the ids used below

`agent_id` and `target_id` are required and must reference existing
records; the API returns 404 otherwise. Resolve the seeded LOCAL
agent and take a target id from the public list:

```bash
AGENT_ID=$(curl -s http://localhost/cgi-bin/api/agents \
  | jq -r '.agents[] | select(.name=="LOCAL") | .id')
TARGET_ID=$(curl -s http://localhost/cgi-bin/api/targets | jq -r '.targets[0].id')
```

## Create a monitor

Basic ICMP monitor:

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"ICMP Monitor\",
    \"protocol\": \"ICMP\",
    \"dscp\": \"BE\",
    \"pollcount\": 5,
    \"pollinterval\": 60
  }" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Monitor created successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

Omitted fields take their defaults: protocol ICMP, port 0, DSCP BE,
pollcount 5, and pollinterval 60.

## Create a TCP monitor

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"Web Server Monitor\",
    \"protocol\": \"TCP\",
    \"port\": 443,
    \"dscp\": \"AF21\",
    \"pollcount\": 3,
    \"pollinterval\": 30
  }" | jq '.'
```

## Retrieve a monitor

Use a monitor id from the list response:

```bash
MONITOR_ID=$(curl -s http://localhost/cgi-bin/api/monitors | jq -r '.monitors[0].id')

curl -s http://localhost/cgi-bin/api/monitor/$MONITOR_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

The response contains the monitor configuration, its current and
lifetime statistics, and the joined agent and target fields.

## Update a monitor

The description, protocol, port, DSCP value, and active flag can be
changed after creation:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/$MONITOR_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Monitor updated successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Deactivate a monitor

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/$MONITOR_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

The response matches the description update above.

## Polling parameters are immutable

`pollcount` and `pollinterval` are fixed when the monitor is created.
An update that touches either is rejected outright with HTTP 400:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/$MONITOR_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pollcount": 10,
    "pollinterval": 120
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Cannot modify polling parameters after monitor creation. Delete and recreate the monitor to change these values."
}
```

## Reset monitor statistics

The reset clears the counters and lifetime averages and stamps
`last_clear`:

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor/$MONITOR_ID/reset \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Monitor statistics reset successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Delete a monitor

Deleting a monitor also deletes its RRD data files. The operation
cannot be undone.

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/monitor/$MONITOR_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Monitor deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Error handling

### Invalid protocol

A create request with an unsupported protocol fails with HTTP 400.
Use the create request shape above with `"protocol": "INVALID"`:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid protocol"
}
```

### Invalid DSCP value

Same request shape with `"dscp": "INVALID"`:

```json
{
  "status": "error",
  "message": "Validation failed: Invalid DSCP value"
}
```

### Invalid port

Same request shape with `"protocol": "TCP"` and `"port": 99999`:

```json
{
  "status": "error",
  "message": "Validation failed: Port must be between 0 and 65535"
}
```

### Duplicate monitor

A monitor is unique on the combination of agent, target, protocol,
port, and DSCP. Repeating the ICMP/BE creation from above with the
same agent and target fails with HTTP 400:

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"protocol\": \"ICMP\",
    \"dscp\": \"BE\"
  }" | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Monitor with these parameters already exists"
}
```

### Monitor not found

Requests that reference an unknown monitor id fail with HTTP 404.
The same error applies to the GET, PUT, and DELETE variants:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/NON-EXISTENT-ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "No such monitor"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Monitor not found"
}
```