# Local development image for Nexora: Apache + PHP 8.2 with what WordPress, WooCommerce, Elementor and the test harness need,
# plus WP-CLI and Composer (for PHPCS). The code is bind-mounted, never copied in. Local use only.
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libicu-dev libonig-dev libmagickwand-dev \
      mariadb-client less unzip git curl ca-certificates \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd zip intl exif bcmath mbstring opcache \
 && pecl install imagick && docker-php-ext-enable imagick \
 && a2enmod rewrite headers expires \
 && rm -rf /var/lib/apt/lists/*

# The site is served at /nexora/ on port 8080 (the same URL inside and outside the container, so the tests' own web requests work).
RUN sed -i 's/Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\nServerName localhost\n' > /etc/apache2/conf-available/nexora.conf \
 && a2enconf nexora

COPY php.ini /usr/local/etc/php/conf.d/zz-nexora.ini

RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# WP-CLI refuses to run as root without a flag; run everything as www-data, who owns the uploads the web server writes.
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data && mkdir -p /var/www/.wp-cli /var/www/.composer && chown -R www-data:www-data /var/www
ENV WP_CLI_CACHE_DIR=/var/www/.wp-cli COMPOSER_HOME=/var/www/.composer
WORKDIR /var/www/html/nexora
