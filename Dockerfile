# use php latest stable image with minimal dependencies
FROM php:8.2-cli

# install dependencies
RUN apt-get update && apt-get install -y \
    git unzip zip \
    && rm -rf /var/lib/apt/lists/*

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# set working directory
WORKDIR /app

# Copy all files
COPY . .

# # Install PHP extensions required by PHPUnit & Infection
# RUN docker-php-ext-install dom mbstring

# Install dependencies
RUN composer install --no-interaction --prefer-dist

RUN chmod +x vendor/bin/phpunit vendor/bin/infection || true

# Prepare necessary directories
RUN mkdir -p workspace/repo workspace/mutants workspace/generated-tests workspace/reports build vendor

# Default command
CMD ["php", "run.php"]

