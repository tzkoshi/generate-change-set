# Use the official PHP image as the base image
FROM php:8.1-cli

# Set working directory inside the container
WORKDIR /app

# Copy the necessary files into the container
COPY . /app

# Install necessary dependencies
RUN apt-get update && \
apt-get install -y \
git \
unzip && \
rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies using Composer
RUN composer install --no-dev --optimize-autoloader

# Generate the PHAR file
RUN php -d phar.readonly=0 build/phar.php

# Make the PHAR file executable
RUN chmod +x /app/dist/changeset.phar

# Set the default command to run the PHAR file
ENTRYPOINT ["php", "/app/dist/changeset.phar"]

# Optionally, if you want to pass arguments directly to the PHAR file
CMD ["--help"]
