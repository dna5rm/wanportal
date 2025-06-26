# Test: Target APIs

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## List all targets (public endpoint)

```bash
curl -s -X GET http://localhost/cgi-bin/api/targets | jq '.'
```

## Create a target with IPv4

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

## Create a target with hostname

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

## Create a target with IPv6

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

## Try to create target with invalid address (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "300.300.300.301",
    "description": "Invalid IP"
  }' | jq '.'
```

## Try to create duplicate target (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.8.8",
    "description": "Duplicate Address"
  }' | jq '.'
```

## Get single target (save an ID from create response)

```bash
curl -s -X GET http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update target description

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated Description"
  }' | jq '.'
```

## Update target address

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "8.8.4.4"
  }' | jq '.'
```

## Deactivate target

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

## Try to update with invalid address (should fail)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "invalid.address.@#$"
  }' | jq '.'
```

## Delete target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Try to delete non-existent target (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/76527336-48B2-11F0-99F2-85E28DBB3913 \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Create target and add monitor, then try to delete (should fail)

### First create target

```bash
TARGET_ID=$(curl -s -X POST http://localhost/cgi-bin/api/target \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "address": "1.1.1.1",
    "description": "Cloudflare DNS"
  }' | jq -r '.id')
```

### Then create monitor for this target

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

### Try to delete the target

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/target/$TARGET_ID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```
