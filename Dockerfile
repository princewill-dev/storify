FROM php:8.4-apache

# 1. Install packages (Same as yours)
RUN apt-get update && apt-get install -y \
    libpng-dev libzip-dev zip unzip git curl supervisor \
    && docker-php-ext-install pdo_mysql bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

# 2. Apache Config (Same as yours)
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf && a2enmod rewrite

# 3. Allow .htaccess
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# 4. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Copy PHP upload configuration
COPY php/conf.d/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# 6. Set Workdir
WORKDIR /var/www/html

# 7. Copy Supervisor Config
RUN mkdir -p /var/log/supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 8. Expose Port
EXPOSE 80

# 9. Start Supervisor as the main process
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]