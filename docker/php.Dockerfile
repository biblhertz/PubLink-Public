FROM php:8.3-fpm
RUN apt-get update && apt-get install -y libpq-dev libpng-dev libjpeg62-turbo-dev zlib1g-dev libfreetype6-dev
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo pdo_mysql sockets gd
RUN apt-get install -y poppler-utils
RUN apt-get install -y procps
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions imagick

RUN apt-get update && apt-get install -y git \
    unzip \
    libzip-dev

RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-pip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN pip3 install lxml bibtexparser xmlformatter --break-system-packages
RUN ln -s /usr/bin/python3 /usr/bin/python

RUN apt-get update && apt-get install -y pandoc

COPY ./publink/html /var/www/html
COPY ./publink/src /var/www/src
RUN mkdir /var/www/file_store
RUN mkdir /var/www/file_store/user
COPY ./publink/job_queue /var/www/job_queue
COPY ./publink/xsd /var/www/xsd
COPY ./publink/logs /var/www/logs
COPY ./publink/logs/job_queue /var/www/logs/job_queue

RUN chown -R www-data:www-data /var/www/job_queue/jats_bibtex
RUN chmod -R 764 /var/www/job_queue/jats_bibtex
RUN chown -R www-data:www-data /var/www/file_store
RUN chmod -R 764 /var/www/file_store
RUN chown -R www-data:www-data /var/www/logs
RUN chmod -R +x /var/www/logs
RUN chown -R www-data:www-data /var/www/job_queue
RUN sed -i 's/\r//' /var/www/job_queue/worker.sh && chmod +x /var/www/job_queue/worker.sh
#RUN /var/www/job_queue/worker.sh

WORKDIR /var/www
# Copy composer files first for layer caching
COPY ./publink/composer.json /var/www/composer.json
# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# Disable advisory blocking (guzzlehttp/psr7 1.x has advisories but is safe for internal use)
RUN composer config --global policy.advisories.block false
# Install dependencies
RUN composer install --optimize-autoloader --no-scripts

COPY ./php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY ./php/php.ini /usr/local/etc/php/php.ini
COPY ./publink/phpunit.xml /var/www/phpunit.xml


COPY ./php/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r//' /entrypoint.sh && chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]