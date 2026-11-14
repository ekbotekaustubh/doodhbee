# ==============================================================================
# DoodhDesk SaaS - Production Multi-Process Dockerfile (Nginx + PHP-FPM 8.4)
# ==============================================================================

FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    icu-dev \
    mysql-client \
    bash

# Configure & Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
        bcmath

# Production PHP settings & OPcache optimization
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "opcache.enable=1" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "opcache.memory_consumption=128" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "opcache.interned_strings_buffer=16" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "opcache.max_accelerated_files=10000" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "opcache.revalidate_freq=0" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "opcache.validate_timestamps=0" >> "$PHP_INI_DIR/conf.d/opcache-recommended.ini" \
    && echo "upload_max_filesize=16M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "post_max_size=16M" >> "$PHP_INI_DIR/conf.d/custom.ini" \
    && echo "memory_limit=256M" >> "$PHP_INI_DIR/conf.d/custom.ini"

WORKDIR /var/www/doodhdesk

# Copy application source
COPY . /var/www/doodhdesk

# Create storage subdirectories and set appropriate permissions
RUN mkdir -p /var/www/doodhdesk/storage/logs \
    /var/www/doodhdesk/storage/backups \
    /var/www/doodhdesk/storage/cache \
    /var/www/doodhdesk/public/uploads \
    /run/nginx \
    && chown -R www-data:www-data /var/www/doodhdesk/storage /var/www/doodhdesk/public/uploads \
    && chmod -R 775 /var/www/doodhdesk/storage /var/www/doodhdesk/public/uploads

# Configure Nginx
COPY ./docker/nginx.conf /etc/nginx/http.d/default.conf

# Configure Supervisor to run both Nginx and PHP-FPM
COPY ./docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
