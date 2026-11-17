#!/usr/bin/env bash
set -e

ROLE="${CONTAINER_ROLE:-app}"

cd /var/www/html

if [ "$ROLE" = "app" ]; then
    echo "============================================================"
    echo " [ACB] Iniciando contenedor principal (Nginx + PHP-FPM)     "
    echo "============================================================"

    rm -f /tmp/acb_app_ready

    # 1. Preparar .env si no existe
    if [ ! -f ".env" ]; then
        echo "-> Creando .env desde .env.example..."
        cp .env.example .env
    fi

    # 2. Asegurar directorios de almacenamiento y caché
    mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache

    # 3. Instalar dependencias PHP con Composer
    echo "-> Instalando dependencias PHP (Composer)..."
    composer install --no-interaction --prefer-dist --optimize-autoloader

    # 4. Instalar dependencias Node.js y compilar assets Vite con variables del .env
    echo "-> Instalando dependencias Frontend y compilando assets (Vite)..."
    if [ -f "package-lock.json" ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
    npm run build

    # 5. Generar APP_KEY si no está definida en .env
    if ! grep -q "^APP_KEY=base64:" .env; then
        echo "-> Generando clave de aplicación (APP_KEY)..."
        php artisan key:generate --force --ansi
    fi

    # 6. Enlace simbólico de almacenamiento público
    echo "-> Configurando enlace simbólico de storage..."
    php artisan storage:link --force --ansi || true

    # 7. Esperar a MySQL y asegurar base de datos
    echo "-> Esperando a MySQL (${DB_HOST:-mysql}:${DB_PORT:-3306})..."
    MAX_RETRIES=30
    COUNT=0
    until mysqladmin ping -h "${DB_HOST:-mysql}" -P "${DB_PORT:-3306}" -u root -p"${MYSQL_ROOT_PASSWORD:-root_acb_2026}" --silent; do
        COUNT=$((COUNT + 1))
        if [ "$COUNT" -ge "$MAX_RETRIES" ]; then
            echo "ERROR: No se pudo conectar a MySQL después de ${MAX_RETRIES} intentos."
            exit 1
        fi
        echo "   Esperando a MySQL... (${COUNT}/${MAX_RETRIES})"
        sleep 2
    done

    echo "-> Verificando base de datos '${DB_DATABASE:-festival_acb}'..."
    mysql -h "${DB_HOST:-mysql}" -P "${DB_PORT:-3306}" -u root -p"${MYSQL_ROOT_PASSWORD:-root_acb_2026}" \
        -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE:-festival_acb}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '${DB_USERNAME:-acb_app}'@'%' IDENTIFIED BY '${DB_PASSWORD:-acb_local_2026}'; GRANT ALL PRIVILEGES ON \`${DB_DATABASE:-festival_acb}\`.* TO '${DB_USERNAME:-acb_app}'@'%'; FLUSH PRIVILEGES;"

    # 8. Migraciones y seeders automáticos
    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
        echo "-> Ejecutando migraciones..."
        php artisan migrate --force --ansi
    fi

    if [ "${RUN_SEEDERS:-true}" = "true" ]; then
        echo "-> Ejecutando seeders oficiales (Convocatoria 2026)..."
        php artisan db:seed --force --ansi
    fi

    # 9. Limpiar cachés para tomar variables actuales
    php artisan config:clear --ansi
    php artisan route:clear --ansi
    php artisan view:clear --ansi

    # 10. Permisos de escritura para www-data
    chown -R www-data:www-data storage bootstrap/cache

    # Marcar contenedor listo para Reverb
    touch /tmp/acb_app_ready

    echo "============================================================"
    echo " [ACB] Aplicación lista en puerto interno 8000              "
    echo "============================================================"
    php-fpm -D
    exec nginx -g "daemon off;"

elif [ "$ROLE" = "reverb" ]; then
    echo "============================================================"
    echo " [ACB] Esperando dependencias para iniciar Laravel Reverb   "
    echo "============================================================"
    while [ ! -f "vendor/autoload.php" ]; do
        echo "   Esperando vendor/autoload.php..."
        sleep 2
    done

    chown -R www-data:www-data storage bootstrap/cache || true

    echo "============================================================"
    echo " [ACB] Servidor WebSocket Reverb escuchando en 0.0.0.0:6080 "
    echo "============================================================"
    exec su -s /bin/bash www-data -c "exec php artisan reverb:start --host=0.0.0.0 --port=6080"
else
    exec "$@"
fi
