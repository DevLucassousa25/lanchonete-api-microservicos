# Uma imagem por serviço, a partir do mesmo Dockerfile:
#   docker build --build-arg SERVICE=pedidos -f docker/php/Dockerfile .
ARG SERVICE

# ---------- 1) Assets do front (só faz algo no web-bff) ----------
FROM node:22-alpine AS assets
ARG SERVICE
WORKDIR /app
COPY services/${SERVICE}/ ./
RUN if [ -f package.json ]; then npm install --no-audit --no-fund && npm run build; else mkdir -p public/build; fi

# ---------- 2) PHP ----------
FROM php:8.3-cli-alpine
ARG SERVICE

RUN apk add --no-cache postgresql-dev linux-headers $PHPIZE_DEPS \
 && docker-php-ext-install pdo_pgsql sockets bcmath pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dependências primeiro (cache de camada)
COPY services/${SERVICE}/composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY services/${SERVICE}/ ./
COPY --from=assets /app/public/build ./public/build
RUN cp -n .env.example .env \
 && composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R 777 storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
# remove \r caso o arquivo tenha sido salvo com quebra de linha do Windows
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
