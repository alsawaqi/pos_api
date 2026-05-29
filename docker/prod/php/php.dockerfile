FROM php:8.4-fpm

COPY docker/prod/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

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
    && apt-get upgrade -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*


RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*    

# Add Microsoft package signing key and repo (securely)
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor \
    | tee /usr/share/keyrings/microsoft.asc.gpg > /dev/null

RUN echo "deb [signed-by=/usr/share/keyrings/microsoft.asc.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
    > /etc/apt/sources.list.d/mssql-release.list

# Update & install the ODBC driver
RUN apt-get update && ACCEPT_EULA=Y apt-get install -y \
    msodbcsql18 \
    mssql-tools18

# ✅ Install SQLSRV PHP extensions (after build tools)
RUN pecl install pdo_sqlsrv sqlsrv \
    && docker-php-ext-enable pdo_sqlsrv sqlsrv

# ✅ Install default PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql opcache

# ✅ ADD THIS: install pcntl so Reverb can catch signals
RUN docker-php-ext-install pcntl

RUN apt-get update && apt-get install -y ffmpeg && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /var/www/html

# ✅ Set permissions for Laravel writable directories
RUN mkdir -p storage/logs bootstrap/cache \
    && touch storage/logs/laravel.log \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/prod/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm"]
