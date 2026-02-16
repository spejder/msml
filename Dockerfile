FROM composer:2.9.5@sha256:f0809732b2188154b3faa8e44ab900595acb0b09cd0aa6c34e798efe4ebc9021 AS build-env

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer config --global github-protocols https

RUN composer install --no-interaction --no-progress \
 && ./vendor/bin/box compile --verbose --no-interaction

# Run the phar file just to make sure it works.
RUN ./msml.phar

FROM php:8.5.3-fpm-alpine@sha256:11987e1900d4888b00cb75b3f21c28964e808252ecaafcd865f3480ba97d7023

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.19 mlmmj=~1.6

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes='["/var/spool/mlmmj:/var/spool/mlmmj"]'
# hadolint ignore=DL3048
LABEL io.whalebrew.config.working_dir='$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
