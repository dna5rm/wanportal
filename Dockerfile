# Dockerfile
FROM alpine:latest

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
RUN apk add --no-cache cronie font-freefont mariadb-client nano rrdtool rrdtool-dev curl jq tzdata

## Build Dependencies
RUN apk add --no-cache build-base boost-dev cmake curl-dev \
    fuse fuse-dev git libc-dev range-v3-dev spdlog-dev

## Perl Lang
RUN apk add --no-cache perl perl-dev perl-app-cpanminus perl-data-uuid perl-regexp-common \
    perl-dbd-mysql perl-dbi perl-crypt-jwt perl-mojolicious perl-try-tiny perl-timedate perl-yaml-xs \
    perl-lwp-useragent-determined perl-io-socket-ssl perl-rrd perl-parallel-forkmanager perl-sys-cpu

## Web Server + PHP
RUN apk add --no-cache apache2 apache2-utils apache2-webdav php84 \
    php84-apache2 php84-curl php84-mysqli php84-session php84-simplexml php84-xml

### Enable mod_cgi
RUN cat <<EOF >>/etc/apache2/conf.d/cgi.conf
LoadModule cgi_module modules/mod_cgi.so
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=\$1
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

## Configure Contabs
RUN cat <<EOF >>/etc/crontabs/root
*/1 * * * * /srv/run-agent.sh > /proc/1/fd/1 2>/proc/1/fd/2
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
RUN chown -R apache:apache *
RUN chmod 755 cgi-bin/api *.pl *.sh
RUN find . -type f -exec chmod 644 {} \;
RUN find . -type d -exec chmod 755 {} \;
RUN rm -rf htdocs/index.html /var/www/localhost /var/cache/apk/*
RUN ln -sf /srv /var/www/localhost
RUN touch /var/log/cron.log

# Exposed ports
EXPOSE 80

# Run services
CMD ( crond -f -s & ) && httpd -D FOREGROUND
