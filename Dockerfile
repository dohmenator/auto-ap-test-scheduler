FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install MySQL extension for PHP and MySQL client
RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN apt-get update && apt-get install -y \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Add MySQL client config to skip SSL (MariaDB client)
RUN printf '[client]\nssl=false\n' > /root/.my.cnf

# Set working directory
WORKDIR /var/www/html

# Copy source files
COPY src/ /var/www/html/

EXPOSE 80