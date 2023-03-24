FROM composer:2.5.5@sha256:89d5a9b12a982aaf92d0ce4b49dca81470962a3da6956de484e1e9aa28d21df2 AS build-env

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer config --global github-protocols https

RUN composer install --no-interaction --no-progress \
 && ./vendor/bin/box compile --verbose --no-interaction

# Run the phar file just to make sure it works.
RUN ./msml.phar

FROM php:8.2.4-alpine3.17@sha256:a4148ca7a6199eaad67484122148597c6721d0974a3dc41d574afd9e6eb9ee54

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.19 mlmmj=~1.3

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'
# hadolint ignore=DL3048
LABEL io.whalebrew.config.working_dir '$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
