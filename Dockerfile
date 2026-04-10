FROM php:apache

LABEL maintainer="Jobertomeu"
LABEL description="iLO Fans Controller - Automatic fan speed control for HP iLO servers"

# Install PHP extensions installer
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install required extensions and supervisor
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions ssh2 pcntl posix && \
    apt-get update && \
    apt-get install -y --no-install-recommends supervisor && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Create app directory
WORKDIR /var/www/html

# Copy application files
COPY favicon.ico ./
COPY ilo-fans-controller.php ./index.php
COPY fan-daemon.php ./
COPY auto-control.json ./
COPY config.inc.php.env ./config.inc.php

# Create data directory for persistent config
RUN mkdir -p /data && \
    chown -R www-data:www-data /var/www/html /data

# Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Environment variables
ENV ILO_HOST=""
ENV ILO_USERNAME=""
ENV ILO_PASSWORD=""
ENV UNRAID_HOST=""
ENV UNRAID_API_KEY=""
ENV MINIMUM_FAN_SPEED=10
ENV AUTO_DAEMON=true

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

EXPOSE 80

# Start supervisor (manages both Apache and fan-daemon)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
