FROM php:8.2-apache

# Set the document root for Apache & enable mod_rewrite
ENV APACHE_DOCUMENT_ROOT /var/www/application/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite

# Install required packages
RUN apt-get update && apt-get install -y \
    apt-utils \
    mariadb-client \
    pngquant \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libmcrypt-dev \
    git \
    curl \
    libicu-dev \
    libxml2-dev \
    libssl-dev \
    libcurl4 \
    libcurl4-openssl-dev \
    ruby \
    build-essential \
    zlib1g-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libxslt1-dev \
    sqlite3 \
    wget

# Configure and install the GD extension
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install gd

# Install other PHP extensions
    RUN docker-php-ext-install \
    mysqli \
    bcmath \
    intl \
    xml \
    curl \
    pdo_mysql \
    zip \
    opcache \
    xsl \
    ast

# Set up Apache server name
RUN echo "ServerName localhost" | tee /etc/apache2/conf-available/fqdn.conf && a2enconf fqdn

# Modify PHP configuration settings
RUN cat "$PHP_INI_DIR/php.ini-development" > "$PHP_INI_DIR/php.ini"
RUN sed -i -e 's/memory_limit = 128M/memory_limit = 1024M/g' \
    -e 's/max_execution_time = 30/max_execution_time = 900/g' \
    -e 's/expose_php = Off/expose_php = On/g' \
    -e 's/error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED & ~E_WARNING/g' \
    -e 's/display_errors = Off/display_errors = On/g' \
    -e 's/display_startup_errors = Off/display_startup_errors = On/g' \
    -e 's/log_errors = On/log_errors = Off/g' \
    -e 's/;realpath_cache_size = 4096k/realpath_cache_size = 2M/g' \
    -e 's/post_max_size = 8M/post_max_size = 512M/g' \
    -e 's/;opcache.max_accelerated_files=10000/opcache.max_accelerated_files=32768/g' \
    -e 's/;opcache.memory_consumption=128M/opcache.memory_consumption=256M/g' \
    $PHP_INI_DIR/php.ini

# Clean up after package installation
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# TODO: Add switch for architecture
# Add snyk (auth must be done manually), integration for CI not yet done
# https://docs.snyk.io/snyk-cli/configure-the-snyk-cli/environment-variables-for-snyk-cli
RUN curl -L https://github.com/snyk/cli/releases/download/v1.1274.0/snyk-linux-arm64 -o snyk && \
    chmod +x ./snyk && \
    mv ./snyk /usr/local/bin/
