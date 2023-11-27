FROM php:8.2-cli-alpine

WORKDIR /app

RUN docker-php-ext-install pdo pdo_mysql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY ["composer.json", "composer.lock", "./"]
RUN composer install

COPY . .
CMD ["./run"]
