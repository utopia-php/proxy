FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    autoconf \
    g++ \
    make \
    linux-headers \
    libstdc++ \
    brotli-dev \
    libzip-dev \
    openssl-dev

RUN docker-php-ext-install \
    pcntl \
    sockets \
    zip

RUN pecl channel-update pecl.php.net && \
    pecl install swoole-6.0.1 && \
    docker-php-ext-enable swoole

RUN pecl channel-update pecl.php.net && \
    pecl install redis && \
    docker-php-ext-enable redis

WORKDIR /app

COPY composer.json composer.lock ./
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader \
    --ignore-platform-req=ext-mongodb \
    --ignore-platform-req=ext-memcached \
    --ignore-platform-req=ext-opentelemetry \
    --ignore-platform-req=ext-protobuf

COPY . .

EXPOSE 8080 8081 8025

CMD ["php", "proxies/http.php"]
