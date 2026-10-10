# Runtime API recipes

Recipes for the runtime-stats endpoint: the JWT-protected system
readout the deliberately minimal public `/health` must not expose. One
read-only GET. It reports host figures (uptime, load, memory, disk) and
monitoring freshness (agent check-ins, newest collection, newest
service check, the agent version census). Any valid token may read it;
admin is not required - the same policy as GET /session. Nothing secret
is returned: capacity and freshness numbers only.

The examples call the API on the local host; adjust the base URL to
match the deployment.

## Acquire a token

```bash
TOKEN=$(curl -s -X POST http://localhost/cgi-bin/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password": "***"}' | jq -r '.token')
```

## Get the runtime snapshot

```bash
curl -s http://localhost/cgi-bin/api/runtime-stats \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

### Expected response

```json
{
  "status": "success",
  "runtime": {
    "host": {
      "scope": "host (container shares the host kernel and mounts)",
      "uptime_seconds": 294340,
      "loadavg": {
        "one_min": 2.79,
        "five_min": 1.76,
        "fifteen_min": 1.7
      },
      "memory": {
        "total_kb": 16607552,
        "available_kb": 7657360
      },
      "disk": {
        "path": "/srv",
        "total_kb": 244504212,
        "used_kb": 70878988,
        "avail_kb": 161132308,
        "use_pct": 31
      }
    },
    "monitoring": {
      "agents_total": 3,
      "agents_reporting": 3,
      "agents_stale": 0,
      "reporting_window": "1h",
      "last_collection": "2026-10-10 05:50:03",
      "last_service_check": "2026-10-10 05:48:04"
    },
    "agent_versions": [
      {
        "agent_version": "0.2.0",
        "count": 1
      },
      {
        "agent_version": null,
        "count": 2
      }
    ]
  }
}
```

## Reading the response

The `host` block describes the HOST, not the container: the CGI process
runs in a container that shares the host kernel and mounts, so load,
memory, uptime and the disk read are the host's figures - the `scope`
field labels them so. Four sources feed it (three /proc reads plus one
fixed `df -kP /srv`) and each fails separately: a source that cannot be
read or parsed renders its field null without touching the rest.
`memory.available_kb` is null on kernels without a MemAvailable row,
and null `uptime_seconds` is an unreadable /proc/uptime, distinct from
0, a just-booted host.

The `monitoring` block is database freshness: agents whose last_seen
is within the reporting window (the last hour) vs everything else,
plus the newest monitor collection (`MAX(last_update)`) and the newest
service check (`MAX(last_check)`) as `YYYY-MM-DD HH:MM:SS` server
timestamps. An empty agents table still reports the counters as 0/0/0;
the timestamps stay null when nothing has ever been collected or
checked, or when the database is unreachable.

The `agent_versions` array is the version census: the self-reported
agent_version each upgraded agent stamps at check-in, grouped with
counts. Agents that never reported a version group under a null
`agent_version` and sort last - in the sample above, two of three
agents have not reported a version yet. An empty fleet is an empty
array, not null.

## Fail-soft semantics

A status readout must always answer, so the route never 500s: every
failing source degrades quietly instead.

- Each host field fails on its own. An unreadable /proc file or a df
  run with no `/srv` row renders that field null while the rest of the
  block still answers.
- The database connection and every freshness query fail separately
  and quietly: whatever cannot be read stays null while the rest
  answers.
- `agent_versions` is the only whole-block null: it is null solely
  when the census query itself fails.
- A null never means zero. Zero is a real reading (0/0/0 agents, a
  just-booted host); null means the source could not be read.

## Error handling

### Missing or invalid token

Every call needs the bearer; the anonymous call - and any expired or
tampered token - is refused with HTTP 401:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  http://localhost/cgi-bin/api/runtime-stats
```

prints `401`. The bare request returns the shared error body:

```bash
curl -s http://localhost/cgi-bin/api/runtime-stats
```

### Expected response

```json
{
  "status": "error",
  "message": "Missing or invalid token"
}
```