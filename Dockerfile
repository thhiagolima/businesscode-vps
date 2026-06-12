# syntax=docker/dockerfile:1.7

###########################################
# Stage 1: build do SPA Vue (dash)
###########################################
FROM node:20-alpine AS frontend-build
WORKDIR /app/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
ARG VITE_API_URL=https://api.businesscode.com.br/v1
ENV VITE_API_URL=${VITE_API_URL}
RUN npm run build
# Saída: /app/frontend/dist/index.html é o entrypoint do SPA (sem rename)

###########################################
# Stage 2: build do site Astro (raiz)
###########################################
FROM node:20-alpine AS site-build
WORKDIR /app/site
COPY site/package*.json ./
RUN npm ci
COPY site/ ./
RUN npm run build
# Saída: /app/site/dist

###########################################
# Stage 3: imagem da aplicação PHP-FPM
###########################################
FROM php:8.2-fpm-alpine AS app

# Extensões PHP necessárias
RUN apk add --no-cache \
        icu-dev oniguruma-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath mbstring gd intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /var/www/html

USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

###########################################
# Stage 4: imagem Nginx com assets estáticos
###########################################
FROM nginx:1.27-alpine AS nginx
COPY docker/nginx/conf.d/ /etc/nginx/conf.d/
COPY --from=frontend-build /app/frontend/dist /var/www/dash
COPY --from=site-build     /app/site/dist     /var/www/site
# public/ do Laravel (para try_files de estáticos no domínio api)
COPY backend/public /var/www/html/public
