# ponytail: minimal PHP Apache image for project_manager
FROM php:8.2-apache

# Install required extensions & enable proxy/rewrite modules
RUN docker-php-ext-install mysqli pdo_mysql && \
    a2enmod rewrite proxy proxy_http proxy_wstunnel

# Copy custom apache virtualhost with WSS proxy
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Copy project files
COPY . /var/www/html/

# Set working directory & permissions for uploads
WORKDIR /var/www/html
RUN mkdir -p uploads && chmod -R 777 uploads

EXPOSE 80
