FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        libwebp \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && apk del .build-deps

COPY php/production-security.ini /usr/local/etc/php/conf.d/99-production-security.ini
RUN set -eux; \
    sed -i \
        -e 's/^pm = .*/pm = dynamic/' \
        -e 's/^pm\.max_children = .*/pm.max_children = 12/' \
        -e 's/^pm\.start_servers = .*/pm.start_servers = 3/' \
        -e 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 2/' \
        -e 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 6/' \
        /usr/local/etc/php-fpm.d/www.conf; \
    if grep -q '^;*pm\.max_requests' /usr/local/etc/php-fpm.d/www.conf; then \
        sed -i 's/^;*pm\.max_requests = .*/pm.max_requests = 500/' /usr/local/etc/php-fpm.d/www.conf; \
    else \
        printf '\npm.max_requests = 500\n' >> /usr/local/etc/php-fpm.d/www.conf; \
    fi
