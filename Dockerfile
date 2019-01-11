FROM php:7-cli

ARG COMPOSER_FLAGS="--prefer-dist --no-interaction"
ARG DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_PROCESS_TIMEOUT 3600

WORKDIR /code

COPY docker/php-prod.ini /usr/local/etc/php/php.ini
COPY docker/composer-install.sh /tmp/composer-install.sh

# Deps
RUN apt-get update
RUN apt-get install -y  --no-install-recommends \
    wget \
    curl \
    make \
    git \
    bzip2 \
    time \
    libzip-dev \
    zip \
    unzip \
    openssl

# Composer
RUN chmod +x /tmp/composer-install.sh && /tmp/composer-install.sh

# Main
ADD . /code
RUN composer install $COMPOSER_FLAGS
