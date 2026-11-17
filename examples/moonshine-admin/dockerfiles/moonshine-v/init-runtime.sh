#!/bin/sh
# Prepares the runtime storage volume on first boot (moonshine-init service).
# Same file layout as the project's docker/init-runtime.sh, but without the
# production validate-environment.php gate: this stack is a local development
# stack where APP_KEY is generated right after this script (see compose.yaml).
set -eu
umask 027
mkdir -p storage/runtime storage/api-admin storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
if [ ! -f storage/runtime/.initialized ]
then
    cp -a /opt/api-admin-seed/. storage/runtime/
    touch storage/runtime/.env
    touch storage/runtime/.initialized
fi

# artisan key:generate only replaces an existing APP_KEY line, it never
# appends one to an empty file, so the key is written here directly.
if [ ! -s storage/runtime/.env ]
then
    php -r 'file_put_contents("storage/runtime/.env", "APP_KEY=base64:" . base64_encode(random_bytes(32)) . PHP_EOL);'
fi

# Keep runtime configuration and previously generated panel layouts in sync.
# A changed upstream anchor is a startup error, not a successful initialization.
php /var/www/html/docker/apply-patches.php --runtime

chown -R 82:82 storage
chmod 700 storage/runtime
chmod 600 storage/runtime/.env
