FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

CMD ["php", "artisan"]
