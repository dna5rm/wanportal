# wanportal Helm chart

K3s-friendly chart for the wanportal dashboard: one pod running the
Apache/PHP/Perl image with a MariaDB sidecar on 127.0.0.1:3306, exposed
through a ClusterIP Service on port 80.

## Design

- Single Deployment, replicaCount 1, strategy Recreate — both containers
  share ReadWriteOnce volumes, so a rolling update would deadlock.
- wanportal container: image wanportal (tag defaults to appVersion, pull
  policy IfNotPresent), containerPort 80, probes on GET /cgi-bin/api/health.
- mariadb sidecar: in-pod only (no Service), 127.0.0.1:3306, own PVC.
- PVCs <name>-mysql (/var/lib/mysql) and <name>-rrd (/var/rrd) with
  storageClass local-path (k3s default provisioner, node-bound
  ReadWriteOnce). Override with persistence.<name>.existingClaim.
- Non-secret env in a ConfigMap (TZ, MYSQL host/port/user/db, LDAP server
  settings); credentials in a Secret (empty by default, or
  secrets.existingSecret with keys MYSQL_PASSWORD, JWT_SECRET, APP_SECRET,
  and AUTH_LDAP_BIND_PASSWORD when LDAP auth is enabled).
- Optional dotenv.existingSecret mounted read-only at /srv/.env for cron.
- Opt-in extras: service.nodePort (NodePort type) and service.dualStack.

## Requirements

- k3s (local-path StorageClass) or any cluster whose default provisioner
  supports ReadWriteOnce volumes.
- The wanportal image present on the node — k3s does not pull locally built
  images. Build it from the repo Dockerfile, import it (k3s ctr images
  import) or push it to a registry, then set image.repository/image.tag.
  For a locally imported image use --set image.tag=latest.

## Render / install (reference)

    helm lint chart/
    helm template wanportal chart/
    helm upgrade --install wanportal chart/ --set image.tag=latest

Chart development here does not run helm install against a cluster.

## Operational notes

- ICMP monitors need root: ICMP ping goes through Perl Net::Ping raw
  sockets, so the container must run as root (its crond already does). Do
  not add a securityContext that sets runAsNonRoot or drops CAP_NET_RAW —
  ICMP monitors freeze while TCP monitors keep updating.
- Cron reads /srv/.env, not the container env: the in-image cron wrappers
  (cron-run-agent, cron-run-notify) source /srv/.env on every run. Provide
  it with --set dotenv.existingSecret=<name>; the Secret needs a key named
  .env with the values the jobs need (MYSQL_*, JWT_SECRET, APP_SECRET,
  AUTH_LDAP_*, TZ).
- Secrets: empty values render an empty Secret and boot MariaDB with an
  empty root password, but the webapp cannot open its database connection
  until a real password is set.
- Storage: local-path volumes are node-bound; with the Recreate strategy
  the pod always lands back on the node holding the PVC data.

## Values

| Key | Default | Description |
| --- | --- | --- |
| replicaCount | 1 | Pod replicas (keep 1: shared RWO PVCs) |
| image.repository | wanportal | Image repository |
| image.tag | "" | Image tag; empty falls back to appVersion |
| image.pullPolicy | IfNotPresent | Pull policy |
| service.type | ClusterIP | Service type |
| service.port | 80 | Service port |
| service.nodePort | "" | Fixed nodePort when type is NodePort |
| service.dualStack.enabled | false | Dual-stack Service and pod IPs |
| persistence.storageClass | local-path | StorageClass for both PVCs |
| persistence.mysql.size | 1Gi | MariaDB data PVC size |
| persistence.rrd.size | 1Gi | RRD data PVC size |
| persistence.*.existingClaim | "" | Use existing claims instead |
| timezone | UTC | TZ env for both containers |
| mariadb.image | mariadb:11.4 | Sidecar image |
| mariadb.database | netops | Database created at first boot |
| mariadb.resources | {} | Sidecar resources |
| secrets.existingSecret | "" | Existing Secret with credential keys |
| secrets.mysqlPassword | "" | MYSQL_PASSWORD (chart Secret) |
| secrets.jwtSecret | "" | JWT_SECRET (chart Secret) |
| secrets.appSecret | "" | APP_SECRET (chart Secret) |
| secrets.authLdapBindPassword | "" | LDAP bind password (chart Secret) |
| dotenv.existingSecret | "" | Secret mounted at /srv/.env for cron |
| auth.ldap.* | false/empty | AUTH_LDAP_* env for the API |
| resources | {} | wanportal container resources |
