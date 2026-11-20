FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY app ./app
COPY components ./components
COPY lib ./lib
COPY public ./public
COPY next.config.mjs postcss.config.mjs tsconfig.json ./
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev unzip git && docker-php-ext-install zip gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/lr
COPY php/ ./php/
RUN composer install --working-dir=php --no-dev --no-interaction --prefer-dist && mkdir -p /var/www/html/api /var/lib/lr && chown www-data:www-data /var/lib/lr && printf '<?php require "/srv/lr/php/index.php";\n' > /var/www/html/api/index.php && printf 'upload_max_filesize=5M\npost_max_size=6M\nmemory_limit=384M\nmax_execution_time=75\n' > "$PHP_INI_DIR/conf.d/lr.ini"
COPY --from=frontend /build/out/ /var/www/html/
