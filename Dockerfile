FROM composer AS build-env

COPY . /opt/msml/

RUN composer global require kherge/box --prefer-dist --update-no-dev
RUN cd /opt/msml && composer install --prefer-dist --no-dev

RUN echo "phar.readonly=false" > "$PHP_INI_DIR/conf.d/phar-not-readonly.ini"
RUN cd /opt/msml && /composer/vendor/bin/box build -v --no-interaction

FROM php:7-alpine

COPY --from=build-env /opt/msml/msml.phar /opt/msml/msml.phar

RUN apk add --update tini mlmmj && rm -rf /var/cache/apk/*

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmj"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'
LABEL io.whalebrew.config.working_dir '$PWD'

ENTRYPOINT ["/sbin/tini", "--", "php", "/opt/msml/msml.phar"]
