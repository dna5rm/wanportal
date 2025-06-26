# Database Schema

## Overview
WanPortal uses MySQL/MariaDB with InnoDB engine and UTF-8 (utf8mb4) character encoding. All tables use UUID primary keys and include appropriate foreign key constraints where relationships exist.

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

**Indexes:**
- PRIMARY KEY (`id`)
- UNIQUE KEY `name` (`name`)
- CHECK CONSTRAINT `chk_valid_ip_address` (validates IP format)

**Notes:**
- System maintains a special 'LOCAL' agent for localhost monitoring
- `address` field accepts both IPv4 and IPv6 formats

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
- `address` can be IPv4, IPv6, or hostname
- Targets can be referenced by multiple monitors

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
| `pollcount` | int(11) | Number of polls per interval |
| `pollinterval` | int(11) | Interval between polls (seconds) |
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
- RTT values stored in milliseconds
- Loss values stored as percentages (0-100)
- Associated RRD files stored in `/var/rrd/{monitor_id}.rrd`

### users
Stores user account information and access control.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `username` | varchar(255) | Unique username |
| `password_hash` | varchar(255) | SHA-256 password hash |
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
- System maintains a special 'admin' user
- Accounts lock after 5 failed attempts
- Lock duration is 30 minutes

### credentials
Stores secure credentials and access tokens.

| Column | Type | Description |
|--------|------|-------------|
| `id` | char(36) | Primary key (UUID) |
| `site` | varchar(255) | Associated site/system |
| `name` | varchar(255) | Credential name |
| `type` | ENUM | Type (ACCOUNT/CERTIFICATE/API/PSK/CODE) |
| `username` | varchar(255) | Associated username |
| `password` | text | Encrypted secret/password |
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
- Supports soft delete via `is_active` flag
- Tracks access history
- Supports structured metadata storage

## Data Storage
- Primary data stored in MySQL/MariaDB
- Time-series data stored in RRD files
- RRD files located in `/var/rrd/`
- Each monitor has its own RRD file named `{monitor_id}.rrd`