# use php latest stable image with minimal dependencies
FROM php:8.2-cli

# install dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip git curl \
    libzip-dev libpng-dev libjpeg-dev \
    libfreetype6-dev libxml2-dev libonig-dev \
    libevent-dev libssl-dev libevent-dev \
    && rm -rf /var/lib/apt/lists/*
    
# # Install PHP extensions required by PHPUnit & Infection
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    zip \
    xml \
    mbstring \
    sockets \
    pcntl

# install event
RUN pecl install event \
    && docker-php-ext-enable --ini-name zz-event.ini event

# install xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# set working directory
WORKDIR /app

# Copy all files
COPY . .

# Install dependencies
RUN composer install --no-interaction --prefer-dist

# Prepare necessary directories
RUN mkdir -p \
    workspace/repo \
    workspace/mutants \
    workspace/generated-tests \
    workspace/reports \
    engine-logs \
    socket-logs \
    vendor

RUN touch socket-logs/socketio.log \ 
    && touch socket-logs/socketio-debug.log

# Ensure bin scripts are executable
RUN chmod +x vendor/bin/phpunit vendor/bin/infection || true

