# Composer for the PHP 7.4 CI cells (D52).
#
# Strauss (D29) needs PHP 8.1 wherever Composer runs, so the 7.4 cells install from the official Composer image and
# then run every test on 7.4. CI builds this with --pull, so it follows the current composer:2. That image's PHP 8.6
# build ships without mbstring, which PHPUnit requires, so it is compiled in here.
FROM composer:2

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev \
	&& apk add --no-cache oniguruma \
	&& docker-php-ext-install mbstring \
	&& apk del .build-deps \
	&& php -m | grep -qx mbstring
