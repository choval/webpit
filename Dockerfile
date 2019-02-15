FROM php:7.3-fpm
MAINTAINER osvaldo@publitar.com

RUN apt-get update && apt-get install -y \
  libxml2-dev zlib1g-dev libpng-dev curl libcurl4-gnutls-dev \
  libfreetype6 libfreetype6-dev \
  libjpeg-dev libpng-dev 

RUN apt-get install -y libzip-dev

RUN docker-php-ext-configure gd \
  --enable-gd-native-ttf \
  --with-freetype-dir=/usr/include/freetype2 \
  --with-png-dir=/usr/include \
  --with-jpeg-dir=/usr/include \
  && docker-php-ext-install sockets pcntl mbstring dom zip pdo_mysql curl gd sqlite3

LABEL org.label-schema.name="webpit" \
  org.label-schema.vendor="PUBLITAR SRL" \
  org.label-schema.description="WebPit - Convert images and videos to WebP" \
  org.label-schema.vcs-url="https://github.com/choval/webpit" \
  org.label-schema.license="Private"

WORKDIR /app
ADD . /app

RUN chown -R www-data /app

CMD /app/server

