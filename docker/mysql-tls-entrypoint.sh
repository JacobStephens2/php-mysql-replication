#!/bin/sh
# The server key must be readable by mysqld and not by other accounts.
# TLS stays optional so the metadata SQL connection can remain unencrypted.
set -eu

cert_dir=/etc/mysql/certs
if [ -f "$cert_dir/server-key.pem" ]; then
    chown mysql:mysql "$cert_dir/server-key.pem"
    chmod 600 "$cert_dir/server-key.pem"
    chmod 644 "$cert_dir/ca.pem" "$cert_dir/server-cert.pem"
fi

exec /usr/local/bin/docker-entrypoint.sh mysqld "$@"
