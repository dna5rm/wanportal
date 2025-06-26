# Test: Credentials API

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## Create a test credential (Cisco Router)

```bash
curl -s -X POST http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "CORE-RTR-01",
    "type": "ACCOUNT",
    "site": "NYC-DC1",
    "username": "admin",
    "password": "Cisco123!",
    "url": "10.1.1.1",
    "owner": "Network Team",
    "comment": "Core router credentials",
    "sensitivity": "HIGH",
    "metadata": {
      "device_type": "cisco_ios",
      "location": "Rack 42-A"
    }
  }' | jq '.'
```

## Create another credential (API Key)

```bash
curl -s -X POST http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "GitHub API Token",
    "type": "API",
    "username": "ci-bot",
    "password": "ghp_123456789abcdef",
    "url": "https://api.github.com",
    "owner": "DevOps Team",
    "comment": "Used for CI/CD",
    "sensitivity": "MEDIUM",
    "metadata": {
      "scopes": ["repo", "packages"]
    }
  }' | jq '.'
```

## List all credentials

```bash
curl -s -X GET http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## List credentials with filters

```bash
curl -s -X GET http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "ACCOUNT",
    "site": "NYC-DC1"
  }' | jq '.'
```

## Get specific credential (replace UUID)

```bash
curl -s -X GET http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Update a credential (replace UUID)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "password": "NewPassword123!",
    "comment": "Updated password during rotation"
  }' | jq '.'
```

## Soft delete a credential (replace UUID)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## List including inactive credentials

```bash
curl -s -X GET http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "show_inactive": true
  }' | jq '.'
```

## List with passwords included (for backup/export)

```bash
curl -s -X GET http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "include_password": true
  }' | jq '.'
```
