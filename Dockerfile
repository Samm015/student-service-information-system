# Stage 1: Build the React/Vite frontend
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build


# Stage 2: Prepare the Laravel application
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        mbstring \
        xml \
        bcmath \
        intl \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Copy the compiled frontend assets
COPY --from=frontend /app/public/build ./public/build

# Install production PHP dependencies
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

# Prepare Laravel's writable directories
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production
ENV APP_DEBUG=false

EXPOSE 10000

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]