# Test: Target APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

Target mutations are admin-only; the detail view works for any
authenticated user.

## List all targets (public endpoint)

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

## Try to create a target with an invalid address (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "300.300.300.301",
    "description": "Invalid IP"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Invalid address format: must be valid IPv4, IPv6, or hostname"
}
```

## Try to create a duplicate target (should fail)

Target addresses are unique — repeat one of the creates above and the
second attempt fails:

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.8.8",
    "description": "Duplicate Address"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Target address already exists"
}
```

## Get a single target (save an id from a create response)

```bash
curl -s http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update the target description

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

## Update the target address

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.4.4"
  }' | jq '.'
```

Address validation applies on update too.

## Deactivate the target

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

## Try to update with an invalid address (should fail)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "invalid.address.@#$"
  }' | jq '.'
```

### Expected response:

```json
{
  "status": "error",
  "message": "Invalid address format: must be valid IPv4, IPv6, or hostname"
}
```

## Delete a target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response:

```json
{
  "status": "success",
  "message": "Target and associated monitors deleted successfully",
  "id": "76527336-48B2-11F0-99F2-85E28DBB3913",
  "deleted_monitors": []
}
```

## Create a target, add a monitor, then delete the target

Deleting a target cascades: its monitors go with it, along with their
RRD files. The delete response lists which monitor ids were removed.

### First create a target

```bash
TARGET_ID=$(curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "1.1.1.1",
    "description": "Cloudflare DNS"
  }' | jq -r '.id')
```

### Then create a monitor for this target

```bash
curl -s -X POST http://localhost/cgi-bin/api/monitor \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"00000000-0000-0000-0000-000000000000\",
    \"target_id\": \"$TARGET_ID\",
    \"description\": \"Test Monitor\"
  }" | jq '.'
```

### Now delete the target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

The delete succeeds: `Target and associated monitors deleted
successfully`, with the monitor id in `deleted_monitors`. The monitor
and its RRD file are gone with the target.