FROM php:8.5-cli

ARG WWWGROUP=1000
ARG NODE_VERSION=22

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"
ENV SUPERVISOR_PHP_USER="sail"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install system packages needed to build PHP extensions and run the app
RUN apt-get update && apt-get install -y --no-install-recommends \
        gosu \
        gnupg \
        git \
        curl \
        unzip \
        libcap2-bin \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libsqlite3-dev \
        default-libmysqlclient-dev \
        supervisor \
        sqlite3 \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions this project needs
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_sqlite \
        pdo_mysql \
        mbstring \
        xml \
        zip \
        bcmath \
        intl \
        gd \
        pcntl

# Install PCOV for test coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer from its official image (no curl/script needed)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Node.js via NodeSource
RUN mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_VERSION}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Allow PHP to bind to port 80 without root
RUN setcap "cap_net_bind_service=+ep" /usr/local/bin/php

# Symlink so any script referencing /usr/bin/php still works
RUN ln -s /usr/local/bin/php /usr/bin/php

# Create the sail user whose ID will be remapped to match your host user
RUN groupadd --force -g $WWWGROUP sail \
    && useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail

RUN git config --global --add safe.directory /var/www/html

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /usr/local/etc/php/conf.d/99-sail.ini
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
