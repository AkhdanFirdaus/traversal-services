FROM php:8.2-fpm

ENV TZ=Asia/Jakarta

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# install xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer*.json ./

# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader

# Copy the rest of the application
COPY . .

# Generate optimized autoload files
RUN composer dump-autoload --optimize

# Create necessary directories with proper permissions
RUN mkdir -p /app/logs/ /app/outputs/ 
RUN chmod -R 777 /app/logs /app/outputs

# Set environment variables
ENV APP_ENV=production