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

# Create necessary directories
RUN mkdir -p workspace/repo workspace/mutants workspace/generated-tests workspace/reports build

# Install dependencies
RUN composer install --no-interaction --prefer-dist

# Run the application
# CMD ["php", "run.php"]

