FROM alpine:edge

COPY composer.json msml /opt/msml/
COPY config/ /opt/msml/config
COPY src/ /opt/msml/src/

RUN echo "@testing http://nl.alpinelinux.org/alpine/edge/testing" >> /etc/apk/repositories && \
    apk update && apk upgrade && \
    apk add composer@testing php5-openssl mlmmj openssh ca-certificates php7@testing php7-json@testing php7-dom@testing  php7-ctype@testing php7-mbstring@testing php7-openssl@testing && \
    cd /opt/msml && php5 /usr/bin/composer install --prefer-dist --ignore-platform-reqs && \
    apk del composer php5 php5-openssl && \
    rm -rf /var/cache/apk/*

WORKDIR /workdir
VOLUME ["/workdir", "/var/spool/mlmmh"]

LABEL io.whalebrew.config.volumes '["/var/spool/mlmmj:/var/spool/mlmmj"]'

ENTRYPOINT ["/opt/msml/msml"]
