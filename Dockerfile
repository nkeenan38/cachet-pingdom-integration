FROM alpine:3.12.0

ENV COMPOSER_VERSION 1.9.0

RUN apk add --no-cache --update \
    php7 \
    php7-redis \
    php7-apcu \
    php7-bcmath \
    php7-dom \
    php7-ctype \
    php7-curl \
    php7-fpm \
    php7-fileinfo \
    php7-gd \
    php7-iconv \
    php7-intl \
    php7-json \
    php7-mbstring \
    php7-mcrypt \
    php7-mysqlnd \
    php7-opcache \
    php7-openssl \
    php7-pdo \
    php7-pdo_mysql \
    php7-pdo_pgsql \
    php7-pdo_sqlite \
    php7-phar \
    php7-posix \
    php7-session \
    php7-sqlite3 \
    php7-simplexml \
    php7-soap \
    php7-xml \
    php7-xmlwriter \
    php7-zip \
    php7-zlib \
    php7-tokenizer

# Install composer
RUN wget https://getcomposer.org/installer -O /tmp/composer-setup.php && \
    wget https://composer.github.io/installer.sig -O /tmp/composer-setup.sig && \
    php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" && \
    php /tmp/composer-setup.php --version=$COMPOSER_VERSION --install-dir=bin && \
    php -r "unlink('/tmp/composer-setup.php');"

WORKDIR /app

ADD . /app
RUN php /bin/composer.phar install -o

RUN mkdir /etc/cron.d/ && \
    echo '* * * * * php /app/src/cron.php' > /etc/cron.d/pingdom && \
    crontab /etc/cron.d/pingdom

CMD [ "crond", "-f", "-l", "0"]