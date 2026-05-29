FROM php:8.4-fpm

COPY docker/dev/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install dependencies
RUN apt-get update && apt-get install -y \
    gnupg2 \
    curl \
    apt-transport-https \
    ca-certificates \
    gnupg \
    unixodbc-dev \
    lsb-release \
    libxml2-dev \
    libssl-dev \
    zip unzip git \
    build-essential autoconf \
    libpq-dev \
    && apt-get upgrade -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Microsoft SQL stuff (you can keep if you want MSSQL in other projects)
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor \
    | tee /usr/share/keyrings/microsoft.asc.gpg > /dev/null

RUN echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
    > /etc/apt/sources.list.d/mssql-release.list

RUN apt-get update && ACCEPT_EULA=Y apt-get install -y \
    msodbcsql18 \
    mssql-tools18

RUN pecl install pdo_sqlsrv sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv sqlsrv

# ✅ Install PHP extensions (including Postgres)
RUN docker-php-ext-install pdo pdo_pgsql opcache

# ✅ pcntl still fine
RUN docker-php-ext-install pcntl

RUN pecl install redis \
    && docker-php-ext-enable redis


RUN apt-get update && apt-get install -y ffmpeg && rm -rf /var/lib/apt/lists/*


# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

RUN mkdir -p storage/logs bootstrap/cache \
    && touch storage/logs/laravel.log \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/dev/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
