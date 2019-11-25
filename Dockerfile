FROM composer:1.9.1 AS build-env

RUN composer global require humbug/box:^3.5 --prefer-dist --update-no-dev

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer install --prefer-dist --no-dev
RUN /tmp/vendor/bin/box build -v --no-interaction

FROM php:7.3.12-alpine

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.18 mlmmj=~1.3

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'
LABEL io.whalebrew.config.working_dir '$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
