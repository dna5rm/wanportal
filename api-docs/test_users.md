# Test: Users API

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"netops"}' | jq -r '.token')
```

## List all users

```bash
curl -s -X GET http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Create a new user (standard user)

```bash
curl -s -X POST http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "jdoe",
    "password": "Test123!@#",
    "full_name": "John Doe",
    "email": "jdoe@example.com",
    "is_admin": false,
    "is_active": true
  }' | jq '.'
```

## Create another user (admin user)

```bash
curl -s -X POST http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "jane.admin",
    "password": "Admin123!@#",
    "full_name": "Jane Smith",
    "email": "jane@example.com",
    "is_admin": true,
    "is_active": true
  }' | jq '.'
```

## Try to create user with weak password (should fail)

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

## Update user (replace USER-UUID with actual UUID)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "full_name": "John Doe Updated",
    "email": "john.new@example.com",
    "is_active": true
  }' | jq '.'
```

## Change user's password (replace USER-UUID)

```bash
curl -s -X PUT http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "password": "NewPass123!@#"
  }' | jq '.'
```

## Try to modify admin user with non-admin token (should fail)

First, get a non-admin token

```bash
NON_ADMIN_TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"jdoe","password":"NewPass123!@#"}' | jq -r '.token')

curl -s -X PUT http://localhost/cgi-bin/api/users/ADMIN-UUID \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "is_active": false
  }' | jq '.'
```

## Delete a user (replace USER-UUID)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/users/USER-UUID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## Try to delete admin user (should fail)

```bash
curl -s -X DELETE http://localhost/cgi-bin/api/users/ADMIN-UUID \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

## List users with non-admin token (should fail)

```bash
curl -s -X GET http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $NON_ADMIN_TOKEN" | jq '.'
```
