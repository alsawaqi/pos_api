FROM php:8.4-cli-alpine

WORKDIR /var/www/html

CMD ["php", "artisan"]
