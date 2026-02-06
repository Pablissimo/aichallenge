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

# Generate concatenated CSS (setup.py is Python 2 and does this + markdown compilation)
RUN cat /var/www/html/css/font.css /var/www/html/css/reset.css /var/www/html/css/layout.css /var/www/html/css/typo.css /var/www/html/css/tab_sync.css /var/www/html/css/pygments_style.css > /var/www/html/aichallenge.css

# Render wiki markdown to HTML fragments and inject as PHP includes
COPY docker/website/inject_markdown.php /tmp/inject_markdown.php
RUN apt-get update && apt-get install -y --no-install-recommends git cmark ca-certificates \
    && git clone --depth 1 https://github.com/aichallenge/aichallenge.wiki.git /tmp/wiki \
    && mkdir -p /var/www/html/wiki \
    && php /tmp/inject_markdown.php \
    && rm -rf /tmp/wiki /tmp/inject_markdown.php \
    && apt-get purge -y git && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Copy visualizer for replay viewing (flatten data/img/ to img/ to match expected layout)
COPY ants/visualizer/js/ /var/www/html/visualizer/js/
COPY ants/visualizer/data/img/ /var/www/html/visualizer/img/

# Copy PHPSkills for TrueSkill calculation (used by api_game_result.php)
COPY manager/PHPSkills/ /var/www/html/manager/PHPSkills/

# Copy entrypoint
COPY docker/website/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

# Create directories
RUN mkdir -p /var/aichallenge/uploads /var/aichallenge/maps /var/aichallenge/replays /var/aichallenge/logs \
    && chown -R www-data:www-data /var/aichallenge /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
