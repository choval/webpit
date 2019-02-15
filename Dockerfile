FROM php:7.3-fpm
MAINTAINER osvaldo@publitar.com

RUN apt-get update && apt-get install -y \
  libxml2-dev zlib1g-dev curl libcurl4-gnutls-dev \
  libjpeg-dev libpng-dev libzip-dev

RUN docker-php-ext-install sockets pcntl mbstring dom zip curl 

LABEL org.label-schema.name="webpit" \
  org.label-schema.vendor="PUBLITAR SRL" \
  org.label-schema.description="WebPit - Convert images and videos to WebP" \
  org.label-schema.vcs-url="https://github.com/choval/webpit" \
  org.label-schema.license="MIT"

WORKDIR /app
ADD . /app

RUN apt-get install -y wget

RUN mkdir -p /app/temp && \
  mkdir -p /app/bin && \
  cd /app/temp && \
  wget -c "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz" && \
  tar xf ffmpeg-release-amd64-static.tar.xz && \
  cp ffmpeg-*-amd64-static/ffmpeg ../bin/ && \
  wget -c "https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-0.4.1-linux-x86-64.tar.gz" && \
  tar xzvf libwebp-0.4.1-linux-x86-64.tar.gz && \
  cp libwebp-0.4.1-linux-x86-64/bin/cwebp ../bin/

RUN chown -R www-data /app

CMD /app/server

