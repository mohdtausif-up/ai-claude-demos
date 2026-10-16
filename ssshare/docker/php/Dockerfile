FROM php:8.1-fpm-bookworm

# tesseract: OCR for redaction suggestions and search; fonts: text-to-image;
# ffmpeg: metadata stripping and posters for screen recordings; libzip: collection downloads;
# chromium: URL capture (always behind the egress-filtering proxy in bin/capture-proxy.php).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libavif-dev libfreetype6-dev libzip-dev \
        tesseract-ocr tesseract-ocr-eng fonts-dejavu-core ffmpeg unzip git \
        chromium fonts-noto-color-emoji libnss3-tools \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd exif pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Extra root CAs (e.g. a corporate TLS-inspection proxy) placed in docker/php/certs/*.crt.
# They are trusted by the system store (curl, composer) and by Chromium's NSS store.
COPY certs/ /usr/local/share/ca-certificates/extra/
RUN update-ca-certificates \
    && mkdir -p /var/www-chrome/nssdb \
    && certutil -d sql:/var/www-chrome/nssdb -N --empty-password \
    && for c in /usr/local/share/ca-certificates/extra/*.crt; do \
         [ -f "$c" ] && certutil -d sql:/var/www-chrome/nssdb -A -t "C,," -n "$(basename "$c")" -i "$c"; \
       done; \
       chown -R www-data:www-data /var/www-chrome

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/ssshare.ini

WORKDIR /var/www
