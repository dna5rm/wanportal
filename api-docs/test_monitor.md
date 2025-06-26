# Test: Monitor APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## List all monitors (public endpoint)

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitors | jq '.'
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

## Try to create monitor with invalid protocol (should fail)

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

## Try to create monitor with invalid DSCP (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "dscp": "INVALID"
  }' | jq '.'
```

## Try to create monitor with invalid port (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "agent_id": "00000000-0000-0000-0000-000000000000",
    "target_id": "F918A070-4843-11F0-BADB-CED674C4600D",
    "protocol": "TCP",
    "port": 99999
  }' | jq '.'
```

## Try to create duplicate monitor (should fail)

Use same agent_id, target_id, protocol, port, and dscp as an existing monitor

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

## Get single monitor (replace with actual ID)

```bash
curl -s -X GET http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update monitor description

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

## Update monitor polling parameters

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pollcount": 10,
    "pollinterval": 120
  }' | jq '.'
```

## Deactivate monitor

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

## Try to update with invalid pollcount (should fail)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pollcount": 999
  }' | jq '.'
```

## Try to update non-existent monitor (should fail)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/monitor/NON-EXISTENT-ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "This should fail"
  }' | jq '.'
```

## Delete monitor

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/monitor/29234F4C-48B5-11F0-A00C-E37EC6BF7404 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Try to delete non-existent monitor (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/monitor/NON-EXISTENT-ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```
