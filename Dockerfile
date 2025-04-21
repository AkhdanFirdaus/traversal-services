# use php latest stable image with minimal dependencies
FROM php:8.4-alpine

# install dependencies
RUN apk update && apk add --no-cache git 

# install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# add composer to env path
ENV PATH="/usr/local/bin:${PATH}"

