FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libgd-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install zip gd pdo pdo_mysql bcmath opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --optimize-autoloader --no-dev --no-interaction --no-scripts

EXPOSE 8080

CMD php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php -S 0.0.0.0:${PORT:-8080} -t public public/server.php
