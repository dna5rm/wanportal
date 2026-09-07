# Test: Credentials API

Log in first. In a dev deployment the seeded admin account is `admin`
with the database password (`netops` in the default compose):

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

Creating, updating, and deleting credentials need an admin token;
reading the list and the details work for any signed-in user.

## Create a credential (device account)

```bash
curl -s -X POST http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "CORE-RTR-01",
    "type": "ACCOUNT",
    "site": "NYC-DC1",
    "username": "svc-netops",
    "password": "<secret-here>",
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

The response carries just the status and the new entry's `id`; only
`name` and `type` are required. If `sensitivity` is omitted it defaults
to MEDIUM.

## Create another credential (API key)

```bash
curl -s -X POST http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "GitHub API Token",
    "type": "API",
    "username": "ci-bot",
    "password": "<paste-the-token-here>",
    "url": "https://api.github.com",
    "owner": "DevOps Team",
    "comment": "Used for CI/CD",
    "sensitivity": "MEDIUM",
    "metadata": {
      "scopes": ["repo", "packages"]
    }
  }' | jq '.'
```

## List credentials

```bash
curl -s http://localhost/cgi-bin/api/credentials \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

By default only active entries come back, and passwords are never
included in list responses.

## List credentials with filters

Filtering happens through query parameters — a JSON body on a GET is
ignored. Exact match is the default; other columns also accept
comparison prefixes (`=`, `!=`, `<`, `<=`, `>`, `>=`), a `%...%`
wildcard for a LIKE match, and the sentinels `NULL` / `NOT NULL`:

```bash
# Exact matches
curl -s "http://localhost/cgi-bin/api/credentials?type=ACCOUNT&site=NYC-DC1" \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# Comparison operator
curl -s "http://localhost/cgi-bin/api/credentials?sensitivity=>=HIGH" \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# LIKE with %wildcards%
curl -s "http://localhost/cgi-bin/api/credentials?name=%CORE%" \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# Null check
curl -s "http://localhost/cgi-bin/api/credentials?expiry_date=NOT%20NULL" \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# Inactive entries only (active-only is the default)
curl -s "http://localhost/cgi-bin/api/credentials?is_active=0" \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

`is_active` accepts only 0 or 1, so there is no way to list both states
in one call.

## Get one credential (replace the id)

```bash
curl -s http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

The stored password appears here only for admin callers, and every
view stamps `last_accessed_at` / `last_accessed_by` with who looked
and when.

## Rotate a password (replace the id)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "password": "<new-secret-here>",
    "comment": "Rotated during the quarterly cycle"
  }' | jq '.'
```

## Delete a credential (replace the id)

The first delete is a soft delete — the entry is just marked inactive.
Deleting the same (now inactive) entry again removes the row for good:

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/credentials/YOUR-UUID-HERE \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

`Credential soft deleted` means it is only hidden; `Credential
permanently deleted` means the row is gone.

## Password visibility policy

Passwords are never included in `GET /credentials` list responses. The
stored password is returned by `GET /credentials/{id}` only to admin
users; other authenticated users receive the credential without the
password field.