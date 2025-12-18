FROM composer:2.9.2@sha256:307e4af1e1206be7508243afa28a0bec8fc8255ce875a16d85a69c2689ea8b1a AS build-env

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer config --global github-protocols https

RUN composer install --no-interaction --no-progress \
 && ./vendor/bin/box compile --verbose --no-interaction

# Run the phar file just to make sure it works.
RUN ./msml.phar

FROM php:8.5.0-fpm-alpine@sha256:562f7fe47f29529d9520e9618a6259ba6475865a67ea4bb4d5601786838babf3

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.19 mlmmj=~1.6

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes='["/var/spool/mlmmj:/var/spool/mlmmj"]'
# hadolint ignore=DL3048
LABEL io.whalebrew.config.working_dir='$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
