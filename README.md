# Sistema de Calificaciones — XXXIII Festival Nacional de la Canción Boliviana “Aquí… Canta Bolivia”

Sistema web en tiempo real desarrollado en **Laravel 12** y **Laravel Reverb** para la gestión de calificaciones, control de escenario, cronómetro sincronizado, jurado secreto, ranking público de gala y reportes notariales del **XXXIII Festival Nacional de la Canción Boliviana “Aquí… Canta Bolivia”** (Oruro, 2026).

---

## 1. Estrategia de Despliegue Centralizada (Docker Compose en Debian 13)

Toda la aplicación está unificada en una sola estrategia de despliegue con **Docker Compose**, diseñada para convivir en un servidor **Debian 13** que ya ejecuta Docker y otros servicios sobre la IP pública, evitando colisiones de puertos.

Para desplegar a producción **solo necesitas editar `.env`** (y, si usas dominio/HTTPS, el bloque de **Nginx** o **Apache** del servidor anfitrión).

### Puertos y Red Aislada (`acb_festival_network` — `172.28.10.0/24`)

| Servicio | Contenedor | Puerto Interno | Puerto en Host (por defecto) | Propósito |
|---|---|---:|---|---|
| `app` | `acb_app` | `8000` (Nginx + PHP-FPM) | `0.0.0.0:9080` | Aplicación web Laravel 12 |
| `reverb` | `acb_reverb` | `6080` | `0.0.0.0:6080` | Servidor WebSocket en tiempo real (Laravel Reverb) |
| `mysql` | `acb_mysql` | `3306` | `127.0.0.1:3307` | Base de datos MySQL 8.0 (solo loopback, sin exposición pública) |
| `phpmyadmin` | `acb_phpmyadmin` | `80` | `127.0.0.1:9086` | Opcional (`--profile tools`), solo loopback |

---

## 2. Despliegue en Producción (Debian 13)

### Paso 1: Verificar puertos disponibles en el servidor

```bash
sudo ss -tulnp | grep -E ':(9080|6080|3307)\b'
```

*(Si no devuelve salida, los puertos están libres).*

### Paso 2: Clonar repositorio y configurar `.env`

```bash
git clone <URL_DEL_REPOSITORIO> aqui_canta_bolivia
cd aqui_canta_bolivia
cp .env.example .env
nano .env
```

Variables clave a ajustar en `.env`:

- **Acceso directo por IP (`http://<IP_SERVIDOR>:9080`)**:
  ```env
  APP_ENV=production
  APP_DEBUG=false
  APP_URL=http://<IP_SERVIDOR>:9080

  APP_BIND_IP=0.0.0.0
  APP_EXTERNAL_PORT=9080
  REVERB_BIND_IP=0.0.0.0
  REVERB_EXTERNAL_PORT=6080

  DB_PASSWORD=<password_segura_bd>
  MYSQL_ROOT_PASSWORD=<password_root_mysql>

  REVERB_APP_KEY=<clave_publica_reverb>
  REVERB_APP_SECRET=<secreto_reverb>

  VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
  VITE_REVERB_PORT=6080
  VITE_REVERB_SCHEME=http
  ```

- **Acceso detrás de Nginx o Apache con dominio y HTTPS (`https://tu-dominio.bo`)**:
  ```env
  APP_ENV=production
  APP_DEBUG=false
  APP_URL=https://tu-dominio.bo

  # Enlazar solo a localhost para que únicamente Nginx/Apache del host acceda
  APP_BIND_IP=127.0.0.1
  APP_EXTERNAL_PORT=9080
  REVERB_BIND_IP=127.0.0.1
  REVERB_EXTERNAL_PORT=6080

  VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
  VITE_REVERB_PORT=443
  VITE_REVERB_SCHEME=https
  ```

### Paso 3: Construir y levantar los contenedores

```bash
docker compose up -d --build
```

Al iniciar, el contenedor `acb_app` automatiza todo el aprovisionamiento inicial:
1. Instala dependencias PHP (`composer install`) y compila los assets Vite (`npm run build`) con los valores actuales de `.env`.
2. Genera `APP_KEY` si está vacía y crea el enlace `public/storage`.
3. Espera a que MySQL esté saludable y verifica/crea la base de datos.
4. Ejecuta **automáticamente las migraciones** (`php artisan migrate --force`) y los **seeders oficiales de la Convocatoria 2026** (`php artisan db:seed --force`).
5. Levanta **Nginx + PHP-FPM** en el puerto `9080` y habilita el contenedor **Reverb** en el puerto `6080`.

Verificar estado y logs:

```bash
docker compose ps
docker compose logs -f app reverb
```

---

## 3. Configuración del Proxy Inverso en el Host (Opcional: Nginx o Apache)

Si deseas publicar la aplicación con dominio propio y SSL (puerto `80`/`443`), configura **Nginx** o **Apache** en el servidor Debian 13 apuntando a `127.0.0.1:9080` y `127.0.0.1:6080`.

### Opción A: Nginx (`/etc/nginx/sites-available/aqui-canta-bolivia`)

```nginx
server {
    listen 80;
    server_name tu-dominio.bo;

    client_max_body_size 32M;

    # Aplicación Laravel (puerto 9080)
    location / {
        proxy_pass http://127.0.0.1:9080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSockets Laravel Reverb (puerto 6080)
    location /app {
        proxy_pass http://127.0.0.1:6080;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location /apps {
        proxy_pass http://127.0.0.1:6080;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Activar y recargar:
```bash
sudo ln -s /etc/nginx/sites-available/aqui-canta-bolivia /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Opción B: Apache (`/etc/apache2/sites-available/aqui-canta-bolivia.conf`)

Habilitar módulos requeridos:
```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers
```

Configuración del VirtualHost:
```apache
<VirtualHost *:80>
    ServerName tu-dominio.bo

    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}

    # WebSockets Laravel Reverb (puerto 6080)
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/app/(.*)$ ws://127.0.0.1:6080/app/$1 [P,L]

    ProxyPass /app http://127.0.0.1:6080/app
    ProxyPassReverse /app http://127.0.0.1:6080/app
    ProxyPass /apps http://127.0.0.1:6080/apps
    ProxyPassReverse /apps http://127.0.0.1:6080/apps

    # Aplicación Laravel (puerto 9080)
    ProxyPass / http://127.0.0.1:9080/
    ProxyPassReverse / http://127.0.0.1:9080/
</VirtualHost>
```

Activar y recargar:
```bash
sudo a2ensite aqui-canta-bolivia.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

*(Para HTTPS, ejecutar `sudo certbot --nginx -d tu-dominio.bo` o `sudo certbot --apache -d tu-dominio.bo`).*

---

## 4. Migraciones, Seeders y Comandos Útiles

### Credenciales Iniciales (Seeder Oficial)

| Rol | Correo | Contraseña |
|---|---|---|
| `ADMIN` | `admin@acb.local` | `admin123` |

> Cambiar la contraseña desde el panel de administración inmediatamente después del primer ingreso en producción.

### Comandos Docker Compose y Artisan

| Acción | Comando |
|---|---|
| Levantar servicios | `docker compose up -d` |
| Reconstruir tras cambios en Dockerfile | `docker compose up -d --build` |
| Recompilar assets tras editar `.env` (`VITE_*`) | `docker compose up -d --force-recreate app reverb` |
| Ver logs en vivo | `docker compose logs -f app reverb` |
| Ejecutar migraciones manualmente | `docker compose exec app php artisan migrate --force` |
| Ejecutar seeders oficiales manualmente | `docker compose exec app php artisan db:seed --force` |
| Limpiar cachés de Laravel | `docker compose exec app php artisan optimize:clear` |
| Reiniciar presentaciones de prueba | `docker compose exec app php artisan festival:reset-presentaciones` |
| Levantar phpMyAdmin (solo `127.0.0.1:9086`) | `docker compose --profile tools up -d` |
| Respaldar base de datos MySQL | `docker compose exec mysql mysqldump -u root -p festival_acb > backup_$(date +%F).sql` |
| Ejecutar suite de pruebas (PHPUnit) | `docker compose exec app php artisan test` |
| Detener todo el stack | `docker compose down` |
