FROM composer:2.4.4@sha256:09d472357d154632b613bfd2ed997a52fbf343f123c23e17537b47f85049b36d AS build-env

COPY . /opt/msml/

WORKDIR /opt/msml

RUN composer config --global github-protocols https

RUN composer install --no-interaction --no-progress \
 && ./vendor/bin/box compile --verbose --no-interaction

# Run the phar file just to make sure it works.
RUN ./msml.phar

FROM php:8.2.0-alpine3.17@sha256:322cf41cacb3e13d0a16f842219d9b412e6933663ed6f9efefd3257cfc89643d

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --no-cache tini=~0.19 mlmmj=~1.3

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'
# hadolint ignore=DL3048
LABEL io.whalebrew.config.working_dir '$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
