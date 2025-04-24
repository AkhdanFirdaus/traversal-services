# use php latest stable image with minimal dependencies
FROM php:8.4-alpine

# install dependencies
RUN apk update && apk add --no-cache git 

# set working directory
WORKDIR /app

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install composer dependencies
RUN composer install --no-interaction --prefer-dist

# Copy composer files first for caching
COPY composer.json composer.lock /app/
COPY .env.example .env

