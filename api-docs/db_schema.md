# Database Schema

## Overview
WanPortal uses MySQL/MariaDB with the InnoDB storage engine and UTF-8 (`utf8mb4`) character encoding. All tables use UUID primary keys and include appropriate foreign key constraints where relationships exist.

## Tables

### agents
Stores monitoring agent information and credentials.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `name` | varchar(255) | Unique agent name |
| `address` | varchar(255) | IP address (IPv4 or IPv6) |
| `description` | varchar(255) | Optional description |
| `last_seen` | datetime | Timestamp of last agent check-in |
| `is_active` | tinyint(1) | Active status flag |
| `password` | varchar(255) | Agent authentication password |
| `supports_services` | tinyint(1) | Service-polling capability flag (default 0) |
| `agent_version` | varchar(32) | Agent software version, self-reported at check-in |

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `name` (`name`)
- CHECK CONSTRAINT `chk_valid_ip_address` (validates IP format)

**Notes:**
- The system maintains a special `LOCAL` agent for localhost monitoring
- The `address` field accepts both IPv4 and IPv6 formats
- `supports_services` (default 0) and `agent_version` are additive
  capability columns, self-declared by an upgraded agent when it first
  fetches its service assignments; every pre-existing row reads as
  unable to poll services until then

### targets
Stores monitoring target information.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `address` | varchar(255) | Target address (IP or hostname) |
| `description` | varchar(255) | Optional description |
| `is_active` | tinyint(1) | Active status flag |

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `address` (`address`)

**Notes:**
- The `address` column accepts an IPv4 address, an IPv6 address, or a hostname
- A target may be referenced by multiple monitors

### monitors
Stores monitoring configurations and statistics.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `description` | varchar(255) | Optional description |
| `agent_id` | char(36) | Reference to agents.id |
| `target_id` | char(36) | Reference to targets.id |
| `protocol` | varchar(10) | Protocol (ICMP/ICMPV6/TCP) |
| `port` | int(11) | Port number (for TCP) |
| `dscp` | varchar(10) | DSCP marking |
| `pollcount` | int(11) | Pings per probe cycle (the agent caps each cycle at five) |
| `pollinterval` | int(11) | Seconds between poll cycles |
| `is_active` | tinyint(1) | Active status flag |
| `sample` | bigint(20) | Number of samples collected |
| `current_loss` | int(11) | Current packet loss percentage |
| `current_median` | float | Current median RTT |
| `current_min` | float | Current minimum RTT |
| `current_max` | float | Current maximum RTT |
| `current_stddev` | float | Current RTT standard deviation |
| `avg_loss` | int(11) | Average packet loss percentage |
| `avg_median` | float | Average median RTT |
| `avg_min` | float | Average minimum RTT |
| `avg_max` | float | Average maximum RTT |
| `avg_stddev` | float | Average RTT standard deviation |
| `prev_loss` | int(11) | Previous loss percentage |
| `last_clear` | datetime | Last statistics reset time |
| `last_down` | datetime | Last time target was down |
| `last_update` | datetime | Last result update time |
| `total_down` | int(11) | Total down count |

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `monitors_agent_idx` (`agent_id`)
- KEY `monitors_target_idx` (`target_id`)
- UNIQUE KEY `monitor_uniqueness` (`agent_id`, `target_id`, `protocol`, `port`, `dscp`)

**Foreign Keys:**
- `agent_id` REFERENCES `agents` (`id`) ON DELETE CASCADE
- `target_id` REFERENCES `targets` (`id`) ON DELETE CASCADE

**Notes:**
- RTT values are stored in milliseconds
- Loss values are stored as percentages (0-100)
- Each monitor's time-series data is stored in an RRD file at `/var/rrd/{monitor_id}.rrd`

### users
Stores user account information and access control.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `username` | varchar(255) | Unique username |
| `password_hash` | varchar(255) | bcrypt password hash |
| `full_name` | varchar(255) | User's full name |
| `email` | varchar(255) | User's email address |
| `is_admin` | boolean | Administrator flag |
| `is_active` | boolean | Account active flag |
| `last_login` | datetime | Last successful login |
| `failed_attempts` | int | Failed login attempt counter |
| `locked_until` | datetime | Account lock expiry time |
| `password_changed` | datetime | Last password change time |
| `created_at` | datetime | Account creation timestamp |
| `created_by` | varchar(255) | Creator username |
| `updated_at` | datetime | Last update timestamp |
| `updated_by` | varchar(255) | Last updater username |

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `idx_username` (`username`)
- KEY `idx_email` (`email`)

**Notes:**
- The system maintains a special `admin` user, seeded at startup with the
  database password as its initial password
- Passwords are stored as bcrypt hashes
- Accounts lock after five failed attempts
- The lock duration is 30 minutes

### credentials
Stores secure credentials and access tokens.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `site` | varchar(255) | Associated site/system |
| `name` | varchar(255) | Credential name |
| `type` | ENUM | Type (ACCOUNT/CERTIFICATE/API/PSK/CODE) |
| `username` | varchar(255) | Associated username |
| `password` | text | Stored secret value; exposed to administrators only, via the detail endpoint |
| `url` | text | Related URL |
| `owner` | varchar(255) | Credential owner |
| `comment` | text | Additional notes |
| `expiry_date` | timestamp | Expiration date |
| `is_active` | boolean | Active status |
| `sensitivity` | ENUM | Level (LOW/MEDIUM/HIGH/CRITICAL) |
| `metadata` | json | Additional structured data |
| `created_at` | timestamp | Creation timestamp |
| `created_by` | varchar(255) | Creator username |
| `last_accessed_at` | timestamp | Last access time |
| `last_accessed_by` | varchar(255) | Last accessor username |
| `updated_at` | timestamp | Last update time |
| `updated_by` | varchar(255) | Last updater username |

**Indexes:**
- PRIMARY KEY (`id`)
- KEY `idx_credentials_name` (`name`)
- KEY `idx_credentials_type` (`type`)
- KEY `idx_credentials_site` (`site`)

**Notes:**
- Records support soft deletion via the `is_active` flag
- Access history is tracked in `last_accessed_at` and `last_accessed_by`
- Structured metadata is stored in the `metadata` column
- Secrets are stored as provided; visibility is controlled at the API
  layer: list responses never include passwords, and detail responses
  expose them to administrators only

### services
Stores HTTP(S) service check configurations and rolled-up state. A
service ties an agent and target to a URI and an F5-style
send/receive/disable assertion triple: the target supplies the host
and the service supplies everything else, so one target row can back
many checks.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `description` | varchar(255) | Optional description (empty string) |
| `agent_id` | char(36) | Reference to agents.id (the polling agent) |
| `target_id` | char(36) | Reference to targets.id (supplies the host) |
| `scheme` | varchar(8) | URI scheme, `http` or `https` (default `http`) |
| `port` | int(11) | Port number; 0 means the scheme default (default 0) |
| `uri_path` | varchar(512) | URI path; empty or leading `/` (default `/`) |
| `uri_query` | varchar(512) | Query string without the leading `?` |
| `http_method` | varchar(10) | Method, one of GET/POST/PUT/PATCH/HEAD/OPTIONS/DELETE (default GET) |
| `http_headers` | json | Request header map (validated as JSON); NULL when unset |
| `body_encoding` | varchar(16) | Encoding of `body` (text/json/base64/form); required when a body is set |
| `body` | mediumtext | Request body |
| `send_string` | varchar(1024) | F5-style send string; when set it IS the request body, exclusive with `body` |
| `receive_string` | varchar(1024) | Receive assertion; the service is UP only when this matches the decoded body |
| `receive_regex` | tinyint(1) | When 1, `receive_string` is a Perl regex instead of a substring |
| `disable_string` | varchar(1024) | Disable assertion; a match forces DOWN even when `receive_string` also matches |
| `disable_regex` | tinyint(1) | When 1, `disable_string` is a Perl regex instead of a substring |
| `expected_status` | varchar(64) | Accepted status tokens (`NNN` or `NNN-NNN`, e.g. `200-299,301`); NULL means any 2xx/3xx |
| `follow_redirects` | tinyint(1) | Follow 3xx responses (default 0) |
| `verify_tls` | tinyint(1) | Verify the TLS certificate (default 1; turning it off is allowed but recorded) |
| `timeout` | int(11) | Request timeout in seconds (1..120; default 10) |
| `auth_type` | varchar(16) | Auth mode (none/basic/bearer/header); NULL means none |
| `auth_header_name` | varchar(128) | Header name carrying the secret when `auth_type` is `header` |
| `auth_credential_id` | char(36) | Reference to credentials.id; the secret itself never lives on the service |
| `pollcount` | int(11) | Polls per probe cycle, set once at creation (default 1) |
| `pollinterval` | int(11) | Seconds between poll cycles, set once at creation (default 300) |
| `is_active` | tinyint(1) | Active status flag |
| `last_state` | varchar(12) | Last reported state (UP/DOWN/DISABLED/UNKNOWN, default UNKNOWN) |
| `last_status_code` | int(11) | Last observed HTTP response code (0 = no HTTP response) |
| `last_reason` | varchar(64) | Last check reason token (closed set; see api-docs/test_service.md) |
| `last_message` | varchar(255) | Short message from the last check; never the response body |
| `last_check` | datetime | Last time the service was checked |
| `last_change` | datetime | Last state change time |
| `total_down` | int(11) | Total down count |

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `services_uniqueness` (`agent_id`, `target_id`, `scheme`, `port`, `uri_path`, `uri_query`)
- KEY `services_agent_idx` (`agent_id`)
- KEY `services_target_idx` (`target_id`)
- KEY `services_credential_fk` (`auth_credential_id`)

**Foreign Keys:**
- `agent_id` REFERENCES `agents` (`id`) ON DELETE CASCADE
- `target_id` REFERENCES `targets` (`id`) ON DELETE CASCADE
- `auth_credential_id` REFERENCES `credentials` (`id`) ON DELETE RESTRICT

**Notes:**
- Two services with the same agent and target are distinguished by the
  full URI: scheme, port, path, and query all join the uniqueness key,
  so `?model=a` and `?model=b` are two checks by design
- A service never stores a secret: authentication references a
  credentials row by id, and attaching a HIGH or CRITICAL sensitivity
  credential requires an administrator
- Deleting a credential that a service still references is refused
  (RESTRICT — the credentials route answers 409)
- Deleting an agent or target cascades to all its services; deleting a
  service removes its RRD file together with the row
- Each service's time-series data is stored in an RRD file at
  `/var/rrd/service-{service_id}.rrd`
- The rolled-up columns hold only the current values; the RRD is the
  historical record

## Data Storage
- Primary data is stored in MySQL/MariaDB
- Time-series data is stored in RRD files
- RRD files are located in `/var/rrd/`
- Each monitor has its own RRD file named `{monitor_id}.rrd`
- Each service has its own RRD file named `service-{service_id}.rrd`
  beside the monitor files, carrying three data sources: `loss`, `rtt`,
  and `status` (the observed HTTP response code; written `U` when there
  is no HTTP response, and kept out of averaged rollups)
