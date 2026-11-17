FROM nginx:latest

RUN apt-get update && apt-get install -y openssl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# The redirect in the config needs the public HTTPS port. The stock nginx
# entrypoint renders /etc/nginx/templates/*.template with envsubst, so
# ${NGINX_HTTPS_PORT} (passed via compose environment) is resolved at runtime.
COPY dockerfiles/nginx/nginx.conf.template /etc/nginx/templates/default.conf.template

# Certificates generated with mkcert (see docs/src/en/getting-started/installation.md)
# are copied when present. Otherwise a self-signed certificate is created, which
# browsers report as untrusted.
COPY dockerfiles/nginx/certs/ /etc/nginx/ssl/

RUN set -eux; \
    if [ ! -f /etc/nginx/ssl/nginx.crt ] || [ ! -f /etc/nginx/ssl/nginx.key ]; then \
        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout /etc/nginx/ssl/nginx.key \
            -out /etc/nginx/ssl/nginx.crt \
            -subj "/CN=localhost" \
            -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"; \
    fi
