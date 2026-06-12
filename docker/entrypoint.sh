#!/bin/sh
set -e

cd /var/www/html

# storage é um volume montado: garante a estrutura mínima do Laravel
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/app/public \
         storage/logs
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Descobre pacotes (não depende de DB)
php artisan package:discover --ansi || true

# Cacheia config/rotas/views só quando o servidor php-fpm sobe de fato
# (evita recachear em cada `docker compose run ... artisan ...` e nos workers)
if [ "${CONTAINER_ROLE:-app}" = "app" ] && [ "$1" = "php-fpm" ]; then
  php artisan storage:link 2>/dev/null || true
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec "$@"
