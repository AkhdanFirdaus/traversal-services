# use php latest stable image with minimal dependencies
FROM php:8.2-cli

# install dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    curl
    
# install xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# set working directory
WORKDIR /app

# Copy all files
COPY . .

# # Install PHP extensions required by PHPUnit & Infection
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install -j$(nproc) zip \
    && docker-php-ext-install -j$(nproc) xml \
    && docker-php-ext-install -j$(nproc) mbstring

# Install dependencies
RUN composer install --no-interaction --prefer-dist

# Ensure bin scripts are executable
RUN chmod +x vendor/bin/phpunit vendor/bin/infection || true

# Prepare necessary directories
RUN mkdir -p workspace/repo workspace/mutants workspace/generated-tests workspace/reports logs vendor

# Default command
CMD ["php", "run.php"]

