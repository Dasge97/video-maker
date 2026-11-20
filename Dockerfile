# video-maker: php-fpm con la aplicación, ffmpeg y yt-dlp, y nginx delante con la carpeta pública.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --ignore-platform-reqs

FROM php:8.4-fpm-bookworm AS app

# ffmpeg monta los vídeos; yt-dlp descarga las referencias dadas como enlace (necesita python3).
RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg python3 ca-certificates curl unzip \
        libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd zip opcache \
    && curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/video-maker.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-video-maker.conf

# XDG_CACHE_HOME: la caché de fontconfig (las fuentes de los subtítulos) donde www-data puede
# escribir; sin ella cada ffmpeg llenaba sus errores de «Fontconfig error: No writable cache directories».
ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDG_CACHE_HOME=/tmp/cache

WORKDIR /var/www/html
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Symfony lee .env al arrancar. El .env del despliegue no entra en la imagen: aquí va .env.image, con
# valores por defecto y sin secretos. Los secretos llegan por el entorno (secrets.env).
RUN cp .env.image .env \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p var \
    && chown -R www-data:www-data var

COPY docker/php/entrypoint.sh docker/php/worker-loop.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/worker-loop.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
