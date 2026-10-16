# PHP 8.1 FPM with only what the app needs. No Composer dependencies and no apt packages are
# required, so the image builds offline from the official php:8.1-fpm-bookworm base.
FROM php:8.1-fpm-bookworm

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && rm -rf /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
