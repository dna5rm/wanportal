# NetPing Modern Perl Network Monitor Suite

NetPing is a modular, Docker-ready network monitoring and agent reporting API/server written in Perl [Mojolicious::Lite]. It features:

- Agent/target/monitor/user CRUD APIs
- Time-series retention (RRD+MySQL)
- Extensible REST endpoints
- Agent authentication using per-agent passwords
- Self-initializing schema and self-cleaning disk/database
- JWT-protected admin HTTP APIs

## Project Structure

```
.
├── Dockerfile
├── README.md
├── cgi-bin/
│   ├── agent.pm            # Agent CRUD
│   ├── agent_monitors.pm   # NetPing server endpoints
│   ├── api                 # Main Perl CGI entry (single file)
│   ├── auth.pm             # Auentication endpoints
│   ├── monitor.pm          # Monitor CRUD
│   ├── target.pm           # Target CRUD
│   ├── test.pm
│   └── users.pm            # User CRUD
├── docker-compose.yml
├── entrypoint.sh
├── htdocs/                # Web dashboard/static files
│   └── ... (UI/HTML/JS)
└── netping-agent.pl       # Agent probe client
```

## Quick Start (Docker Compose)

1. **Clone this repo and adjust `.env` if desired.**
2. **Build & start containers:**

```sh
docker compose up --build
```

3. Access the API at: http://localhost/cgi-bin/api

## Configuration

All configuration is via environment variables (.env file recommended):

```ini
# .env defaults
MYSQL_HOST=wandb
MYSQL_PORT=3306
MYSQL_USER=root
MYSQL_PASSWORD=netops
MYSQL_DB=netops
JWT_SECRET=Wr9deCWWV&AV58DyH8Wz9Mz%6N6r5@sb
APP_SECRET=HKCr2g+Nn6MUm3H3/hG+2zLhpZgwI+oLrf35vf1+a/M=
```

## Database Self-Initialization

- All required tables (`agents`, `targets`, `monitors`, `users`) are auto-created by the app on launch if needed.
- Admin user is always created with username `admin` and initial password = `${MYSQL_PASSWORD}`.
- **Changing the admin password**: Use the API to update as described below.

## Endpoints & Functionality

### Agent monitor polling/reporting

- `GET /agent/:id/monitors`
Agent fetches assignments (password in JSON body)
- `POST /agent/:id/monitors`
Agent submits ping/loss results (password + results in JSON body)

### Monitor management

- `POST /monitor` (defaults to ICMP/BE as needed)
- `GET /monitor` (list/search)
- `PUT /monitor` (edit parameters)
- `DELETE /monitor` (row + rrd file deleted)

### Target management

- `POST /target`, etc.
On delete, all associated monitors (and RRDs) are removed

### Agent management

- `POST /agent`, etc.
On delete, all associated monitors (and RRDs) are removed

## User/JWT-protected routes

Any other `/api/*` route (admin CRUD) requires a valid JWT token.

## RRD Support

- All probe results update `/var/rrd/<monitor_id>.rrd` (created on first update).
- Deleting a monitor/target/agent via the API deletes all associated RRD files automatically.
- Direct file access is possible via host filesystem or Docker bind-mount.

## Agent Client (netping-agent.pl)

The agent script, suitable for cron or systemd, does:

- `GET` monitor assignments from server (`AGENT_ID`, `PASSWORD`, and `SERVER` in env)
- Pings/monitors as configured
- `POST` results back to API

Usage:

```sh
export AGENT_ID=...
export PASSWORD=...
export SERVER=http://localhost/cgi-bin/api
./netping-agent.pl
```

Works with HTTP or HTTPS (SSL is ignored by default for self-signed/dev certs).

## User Authentication

1. Login for a user:

```sh
curl -X POST http://localhost/cgi-bin/api/login \
  -H 'Content-Type: application/json' -d '{"username":"admin","password":"yourpassword"}'
```

- Returns a JWT token. Use this for all other protected endpoints.

### Example: Update admin password

```sh
# First, get admin's id:
curl -s -X GET http://localhost/cgi-bin/api/users -H "Authorization: Bearer $TOKEN" | jq .

# Then, update password:
curl -X PUT http://localhost/cgi-bin/api/users \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"id":1,"password":"yournewpassword"}'
```

## Development/Customization

- **Modules**: each endpoint is a modular `.pm` file (`agent.pm`, `monitor.pm`, etc.)
- **Auto-table**: Each module ensures its own database table(s) on registration.
- **OAuth extension, logging, rate-limits, etc.** are easy to add (see Mojolicious documentation).
