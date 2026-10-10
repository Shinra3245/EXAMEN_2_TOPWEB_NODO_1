#!/bin/sh
set -eu

: "${APP_KEY:?Falta APP_KEY en las variables de Render}"

if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

case "${PORT:-10000}" in
    ''|*[!0-9]*) echo 'PORT debe ser numérico' >&2; exit 1 ;;
esac

sed -i "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:.*>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
sed -i "s@DocumentRoot .*@DocumentRoot ${APACHE_DOCUMENT_ROOT}@" /etc/apache2/sites-available/000-default.conf

cat > /etc/apache2/conf-enabled/laravel.conf <<'APACHE'
<Directory /var/www/html/public>
    AllowOverride All
    Require all granted
</Directory>
ServerName localhost
APACHE

php artisan config:cache --no-interaction
php artisan migrate --force --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache

exec apache2-foreground
