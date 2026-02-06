FROM php:7.4-apache

# Install dependencies for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends zlib1g-dev && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli
RUN pecl install memcache-4.0.5.2 && docker-php-ext-enable memcache

# PHP configuration
RUN echo "output_buffering = On\ndisplay_errors = Off\nlog_errors = On\nerror_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT" > /usr/local/etc/php/conf.d/aichallenge.ini

# Enable Apache modules
RUN a2enmod rewrite

# Copy Apache config
COPY docker/website/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy website source
COPY website/ /var/www/html/

# Copy visualizer for replay viewing
COPY ants/visualizer/ /var/www/html/visualizer/

# Copy PHPSkills for TrueSkill calculation (used by api_game_result.php)
COPY manager/PHPSkills/ /var/www/html/manager/PHPSkills/

# Copy entrypoint
COPY docker/website/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

# Create directories
RUN mkdir -p /var/aichallenge/uploads /var/aichallenge/maps /var/aichallenge/replays /var/aichallenge/logs \
    && chown -R www-data:www-data /var/aichallenge /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
