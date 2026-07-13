FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git curl zip unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl opcache zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN echo "opcache.enable=1\n\
opcache.validate_timestamps=1\n\
opcache.revalidate_freq=0\n\
realpath_cache_size=4096K\n\
realpath_cache_ttl=600" > /usr/local/etc/php/conf.d/simauto-opcache.ini

WORKDIR /var/www/html
