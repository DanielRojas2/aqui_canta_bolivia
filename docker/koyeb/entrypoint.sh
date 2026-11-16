#!/usr/bin/env bash
set -e

cd /var/www/html

echo "============================================================"
echo " [ACB - Koyeb] Iniciando contenedor (Nginx + PHP-FPM + Reverb)"
echo "============================================================"

# Asegurar enlace de storage público
php artisan storage:link --force --ansi || true

# Verificar APP_KEY
if [ -z "${APP_KEY}" ]; then
    echo "ADVERTENCIA: APP_KEY no está definida en las variables de entorno de Koyeb."
    echo "Generando una APP_KEY temporal (configura APP_KEY fija en Koyeb para mantener sesiones)."
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

# Limpiar cachés previas para tomar las variables de entorno actuales de Koyeb
php artisan config:clear --ansi
php artisan route:clear --ansi
php artisan view:clear --ansi

# Ejecutar migraciones y seeders en BD externa (si está habilitado)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "-> Ejecutando migraciones..."
    php artisan migrate --force --ansi
fi

if [ "${RUN_SEEDERS:-true}" = "true" ]; then
    echo "-> Ejecutando seeders oficiales..."
    php artisan db:seed --force --ansi
fi

chown -R www-data:www-data storage bootstrap/cache

echo "============================================================"
echo " [ACB - Koyeb] Escuchando en puerto 8000"
echo "============================================================"

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/acb.conf
