FROM composer:2.8.2@sha256:12cd736fa74a5ed126b1fe11dc3695380e445b46a4ec7de53203c275d792ead0 AS build-env

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer config --global github-protocols https

RUN composer install --no-interaction --no-progress \
 && ./vendor/bin/box compile --verbose --no-interaction

# Run the phar file just to make sure it works.
RUN ./msml.phar

FROM php:8.3-fpm-alpine@sha256:c2da634fa06c6fb61fc18861795c5a28e5d96574c3257c57d0b49978fd1147a3

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.19 mlmmj=~1.4

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'
# hadolint ignore=DL3048
LABEL io.whalebrew.config.working_dir '$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
