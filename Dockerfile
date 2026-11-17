FROM php:8.2-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# Dependencias del sistema, Nginx, cliente MySQL, extensiones PHP y Node.js 20
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    curl \
    ca-certificates \
    gnupg \
    git \
    unzip \
    zip \
    default-mysql-client \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configuración PHP para carga de fotografías e importaciones XLSX/CSV/JSON
RUN { \
    echo "upload_max_filesize=32M"; \
    echo "post_max_size=32M"; \
    echo "memory_limit=256M"; \
    echo "max_execution_time=120"; \
} > /usr/local/etc/php/conf.d/acb.ini

# Composer 2 oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Configuración Nginx interna del contenedor app (puerto interno 8000)
COPY docker/app/nginx.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Script de arranque unificado (roles: app | reverb)
COPY docker/app/entrypoint.sh /usr/local/bin/acb-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/acb-entrypoint.sh && chmod +x /usr/local/bin/acb-entrypoint.sh

EXPOSE 8000 6080

ENTRYPOINT ["/usr/local/bin/acb-entrypoint.sh"]
