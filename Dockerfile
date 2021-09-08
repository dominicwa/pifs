FROM php:7.4.6-apache

RUN apt-get update
RUN apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
RUN sed -i 's/variables_order = "GPCS"/variables_order = "EGPCS"/' "$PHP_INI_DIR/php.ini"

COPY cache/ /var/www/html/cache
COPY docs/images/logo.gif /var/www/html/logo.gif
COPY pifs.php /var/www/html/index.php

RUN chown www-data:www-data -R /var/www/html