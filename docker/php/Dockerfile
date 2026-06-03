FROM php:8.3-fpm

ARG TZ=America/Argentina/Buenos_Aires
ENV TZ=${TZ}

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    tzdata \
    libzip-dev \
    zip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo ${TZ} > /etc/timezone \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    mbstring \
    zip \
    exif \
    pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/99-timezone.ini /usr/local/etc/php/conf.d/99-timezone.ini

WORKDIR /var/www/html
