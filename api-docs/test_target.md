# Target API recipes

Recipes for the target management endpoints. Mutations require an
administrator token; the detail view is available to any authenticated
user, and the target list is public. A target address may be an IPv4
address, an IPv6 address, or a hostname, and addresses are unique.

The examples call the API on the local host; adjust the base URL to
match the deployment.

## Acquire a token

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## List all targets

The list endpoint is public and requires no token:

```bash
curl -s http://localhost/cgi-bin/api/targets | jq '.'
```

## Create a target with an IPv4 address

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.8.8",
    "description": "Google DNS",
    "is_active": true
  }' | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Target created successfully",
  "id": "12345678-1234-5678-1234-567812345678"
}
```

## Create a target with a hostname

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "google.com",
    "description": "Google Website",
    "is_active": true
  }' | jq '.'
```

The response matches the IPv4 create above.

## Create a target with an IPv6 address

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "2001:4860:4860::8888",
    "description": "Google DNS IPv6",
    "is_active": true
  }' | jq '.'
```

The response matches the IPv4 create above.

## Retrieve a target

Use an id returned by a create call, or take one from the list
response:

```bash
TARGET_ID=$(curl -s http://localhost/cgi-bin/api/targets | jq -r '.targets[0].id')

curl -s http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update the target description

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

## Update the target address

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.4.4"
  }' | jq '.'
```

Address validation applies on update as well.

## Deactivate the target

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

The update responses match the shape
`{"status": "success", "message": "Target updated successfully", "id": "..."}`.

## Delete a target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "Target and associated monitors deleted successfully",
  "id": "12345678-1234-5678-1234-567812345678",
  "deleted_monitors": []
}
```

Deleting a target cascades: its monitors are removed along with their
RRD files, and `deleted_monitors` lists the ids of the monitors that
were removed.

## Error handling

### Invalid address

Addresses must be valid IPv4, IPv6, or hostnames. The create request
fails with HTTP 400:

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "300.300.300.301",
    "description": "Invalid IP"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Invalid address format: must be valid IPv4, IPv6, or hostname"
}
```

### Duplicate address

Target addresses are unique. Re-creating an existing address fails
with HTTP 400:

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.8.8",
    "description": "Duplicate Address"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Target address already exists"
}
```

### Invalid address on update

The same validation rejects an invalid address on update, with the
same message as the create case:

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "invalid.address.@#$"
  }' | jq '.'
```

## Cascading delete

The following sequence creates a target with a monitor and then
deletes the target, demonstrating the cascade.

### 1. Create a target

```bash
TARGET_ID=$(curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "1.1.1.1",
    "description": "Cloudflare DNS"
  }' | jq -r '.id')
```

### 2. Create a monitor for this target

Resolve the seeded LOCAL agent first, then create the monitor with
default polling parameters:

```bash
AGENT_ID=$(curl -s http://localhost/cgi-bin/api/agents \
  | jq -r '.agents[] | select(.name=="LOCAL") | .id')

curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"Test Monitor\"
  }" | jq '.'
```

### 3. Delete the target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

The delete succeeds with `Target and associated monitors deleted
successfully`, and `deleted_monitors` contains the monitor id. The
monitor and its RRD file are removed together with the target.