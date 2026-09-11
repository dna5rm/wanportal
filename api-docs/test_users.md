# Test: Users API

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

Every `/users` route requires an admin token.

## List all users

```bash
curl -s http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Create a user (standard user)

```bash
curl -s -X POST http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "deskuser",
    "password": "<user-password>",
    "full_name": "Service Desk",
    "email": "deskuser@example.com",
    "is_admin": false,
    "is_active": true
  }' | jq '.'
```

Passwords require at least eight characters, including at least one
letter and one digit.

## Create a user (admin user)

```bash
curl -s -X POST http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "netops.admin",
    "password": "<admin-password>",
    "full_name": "NOC Administrator",
    "email": "netops-admin@example.com",
    "is_admin": true,
    "is_active": true
  }' | jq '.'
```

## Attempt to create a user with a weak password (should fail)

```bash
curl -s -X POST http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "weakpass",
    "password": "123456",
    "full_name": "Weak Password"
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Password does not meet complexity requirements"
}
```

## Update a user (replace USER-UUID)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "Service Desk (evenings)",
    "email": "desk-evenings@example.com",
    "is_active": true
  }' | jq '.'
```

## Change a user's password (replace USER-UUID)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "password": "<new-user-password>"
  }' | jq '.'
```

## Attempt to modify the built-in admin with a non-admin token (should fail)

First obtain a non-admin token (for the standard user created above),
then attempt to deactivate the built-in admin account:

```bash
NON_ADMIN_TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"deskuser","password":"<user-password>"}' | jq -r '.token')

curl -s -X PUT http://localhost/cgi-bin/api/users/ADMIN-UUID \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

A non-admin caller is rejected by the admin-token check before the
built-in-admin protection applies; the built-in `admin` account can be
changed only by the `admin` user itself.

## Delete a user (replace USER-UUID)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "message": "User deleted successfully",
  "id": "USER-UUID"
}
```

## Attempt to delete the admin user (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/users/ADMIN-UUID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Cannot delete admin user"
}
```

## List users with a non-admin token (should fail)

```bash
curl -s http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "error",
  "message": "Unauthorized"
}
```