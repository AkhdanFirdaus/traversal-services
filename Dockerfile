FROM php:8.2-cli

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
COPY composer.json composer.lock ./

# Install dependencies
RUN composer install --no-dev --no-scripts --no-autoloader

# Copy the rest of the application
COPY . .

# Generate optimized autoload files
RUN composer dump-autoload --optimize

# Create necessary directories with proper permissions
RUN mkdir -p \
    /app/tmp/clones_cli \
    /app/tmp/clones_api \
    /app/output/heuristic_analysis \
    /app/output/msi_output \
    /app/output/exported_test_cases_cli \
    /app/output/exported_test_cases_api \
    && chmod -R 777 /app/tmp /app/output

# Set environment variables
ENV APP_ENV=production

# Command to run the application
CMD ["php", "bin/run.php"] 