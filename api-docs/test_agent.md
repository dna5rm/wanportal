# Test: Agent APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## Get single agent (includes password when authenticated)

```bash
curl -s -X GET http://localhost/cgi-bin/api/agent/00000000-0000-0000-0000-000000000000 \
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

## Create new agent

```bash
curl -s -X POST http://localhost/cgi-bin/api/agent \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "TEST-AGENT-1",
    "address": "192.168.1.100",
    "description": "Test Agent 1",
    "password": "SecurePass123",
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

## Update existing agent (save the ID from create response)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/agent/12345678-1234-5678-1234-567812345678 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Test Agent 1",
    "address": "192.168.1.101",
    "password": "NewPassword123"
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

## Delete agent

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/agent/12345678-1234-5678-1234-567812345678 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "message": "Agent deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Try to delete LOCAL agent (should fail)

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

## Try to create agent with invalid IP

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

## Try to create agent with duplicate name

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
  "message": "Failed to create agent: Duplicate entry 'LOCAL' for key 'name'"
}
```
