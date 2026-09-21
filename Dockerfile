# ponytail: minimal PHP Apache image for project_manager
FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install mysqli pdo_mysql && \
    a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Set working directory & permissions for uploads
WORKDIR /var/www/html
RUN mkdir -p uploads && chmod -R 777 uploads

EXPOSE 80
