FROM php:8.4.18-cli-alpine3.23

RUN apk update && apk upgrade && apk add --no-cache \
    autoconf \
    g++ \
    make \
    linux-headers \
    libstdc++ \
    brotli-dev \
    libzip-dev \
    openssl-dev \
    && rm -rf /var/cache/apk/*

RUN docker-php-ext-install \
    pcntl \
    sockets \
    zip

RUN pecl channel-update pecl.php.net

RUN pecl install swoole && \
    docker-php-ext-enable swoole

RUN pecl install redis && \
    docker-php-ext-enable redis

WORKDIR /app

COPY composer.json ./
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install \
    --no-dev \
    --optimize-autoloader

COPY . .

RUN addgroup -S app && adduser -S -G app app
USER app

EXPOSE 8080 8081 8025

CMD ["php", "examples/http.php"]
