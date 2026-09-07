# Dockerfile
FROM alpine:3.21

ENV CRYFS_NO_UPDATE_CHECK=TRUE
ENV CRYFS_FRONTEND=noninteractive
ENV MOJO_MODE=development
ENV NONINTERACTIVE_TESTING=1
ENV PERL_MM_USE_DEFAULT=1
ENV PYTHONUNBUFFERED 1

WORKDIR /srv
COPY . .

# Install Packages & Update
RUN apk -q update && apk -q upgrade

## Core Packages
RUN apk add --no-cache bash cronie font-freefont mariadb-client nano py3-pip rrdtool rrdtool-dev curl jq tzdata

## Build Dependencies
RUN apk add --no-cache build-base boost-dev cmake curl-dev \
    fuse fuse-dev git libc-dev range-v3-dev spdlog-dev

## Perl Lang
RUN apk add --no-cache perl perl-dev perl-app-cpanminus perl-data-uuid perl-regexp-common perl-email-mime \
    perl-dbd-mysql perl-dbi perl-crypt-jwt perl-mojolicious perl-try-tiny perl-timedate perl-yaml-xs \
    perl-lwp-useragent-determined perl-io-socket-ssl perl-rrd perl-parallel-forkmanager perl-sys-cpu \
    perl-net-ldap perl-crypt-eksblowfish

## Python / Ansible
RUN python3 -m venv /opt/venv && \
    /opt/venv/bin/pip install --no-cache-dir --upgrade pip && \
    /opt/venv/bin/pip install --no-cache-dir ansible ansible-vault dnspython fqdn && \
    /opt/venv/bin/ansible-galaxy collection install ansible.netcommon && \
    /opt/venv/bin/ansible-galaxy collection install community.general

ENV PATH="/opt/venv/bin:$PATH"

## Web Server + PHP
RUN apk add --no-cache apache2 apache2-utils apache2-webdav php84 \
    php84-apache2 php84-curl php84-mysqli php84-session php84-simplexml php84-xml

# AllowOverride within DocumentRoot
RUN sed -i '/<Directory "\/var\/www\/localhost\/htdocs">/,/<\/Directory>/s/AllowOverride None/AllowOverride All/' /etc/apache2/httpd.conf

### Enable mod_cgi
RUN cat <<EOF >>/etc/apache2/conf.d/cgi.conf
LoadModule cgi_module modules/mod_cgi.so
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=\$1

# Pass environment variables to CGI scripts
PassEnv MYSQL_HOST
PassEnv MYSQL_PORT
PassEnv MYSQL_USER
PassEnv MYSQL_PASSWORD
PassEnv MYSQL_DB
PassEnv JWT_SECRET
PassEnv APP_SECRET
PassEnv AUTH_LDAP_ENABLED
PassEnv AUTH_LDAP_SERVER_URI
PassEnv AUTH_LDAP_BIND_DN
PassEnv AUTH_LDAP_BIND_PASSWORD
PassEnv AUTH_LDAP_USER_SEARCH_BASEDN
PassEnv AUTH_LDAP_USER_SEARCH_ATTR
PassEnv LDAP_IGNORE_CERT_ERRORS
EOF

### Enable mod_rewrite
RUN cat <<EOF >>/etc/apache2/conf.d/rewrite.conf
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
EOF

### Server identity hardening
RUN cat <<EOF >>/etc/apache2/conf.d/security.conf
ServerTokens Prod
ServerSignature Off
EOF

### Directory: /api-docs
RUN cat <<EOF >>/etc/apache2/conf.d/api-docs.conf
<Directory /var/www/localhost/api-docs>
    Options Indexes FollowSymLinks
    AllowOverride None
    Require all granted
    
    # Add YAML mime type if not already defined
    AddType application/yaml yml
    AddType application/yaml yaml
</Directory>

# Make sure this matches your Directory path
Alias /api-docs /var/www/localhost/api-docs
EOF

## Cron wrappers live in the image, not the bind mount. They drop to
## apache so a rewritten /srv/run-*.sh cannot run as root.
RUN mkdir -p /usr/local/sbin
RUN cat >/usr/local/sbin/cron-run-agent <<'EOF'
#!/bin/sh
# ICMP via Net::Ping needs raw sockets (root). Notify jobs drop to apache.
if [ -r /srv/.env ]; then
  set -a
  # shellcheck disable=SC1091
  . /srv/.env
  set +a
fi
exec /srv/run-agent.sh
EOF
RUN cat >/usr/local/sbin/cron-run-notify <<'EOF'
#!/bin/sh
if [ -r /srv/.env ]; then
  set -a
  # shellcheck disable=SC1091
  . /srv/.env
  set +a
fi
exec su -p -s /bin/sh apache -c 'exec /srv/run-notify.sh'
EOF
RUN chmod 755 /usr/local/sbin/cron-run-agent /usr/local/sbin/cron-run-notify
RUN cat <<EOF >>/etc/crontabs/root
*/1 * * * * /usr/local/sbin/cron-run-agent  > /proc/1/fd/1 2>/proc/1/fd/2
*/5 * * * * /usr/local/sbin/cron-run-notify > /proc/1/fd/1 2>/proc/1/fd/2
* * */1 * * su -s /bin/sh apache -c 'find /tmp -name "sess_*" -type f -mmin +180 -delete'
EOF

# # Build: CryFS
# RUN git clone --depth 1 https://github.com/cryfs/cryfs.git /usr/local/src/cryfs && \
#     mkdir -p /usr/local/src/cryfs/build && \
#     cd /usr/local/src/cryfs/build && \
#     cmake -Wno-dev .. && \
#     make -j$(nproc) && \
#     make install && \
#     cd / && \
#     rm -rf /usr/local/src/cryfs

# Fix Permissions & Cleanup
RUN install -d -o root -g root -m 775 /etc/cron.d
RUN install -d -o apache -g apache -m 775 /var/rrd
RUN install -d -o apache -g apache -m 775 /var/run/lock/dav
RUN find . -type f -exec chmod 644 {} \;
RUN find . -type d -exec chmod 755 {} \;
RUN rm -rf htdocs/index.html /var/www/localhost /var/cache/apk/*
RUN ln -sf /srv /var/www/localhost
RUN touch /var/log/cron.log
RUN chown -R apache:apache *
RUN chmod 755 cgi-bin/api *.pl *.sh

# Exposed ports
EXPOSE 80

# Run services
CMD ( crond -f -s & ) && httpd -D FOREGROUND
