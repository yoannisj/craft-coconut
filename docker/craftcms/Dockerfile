# Define build arguments available outside build stage (before first FROM instruction)
ARG PHP_VERSION=7.4
ARG CRAFT_APP_TYPE=php-fpm

FROM craftcms/${CRAFT_APP_TYPE}:${PHP_VERSION}-dev

# Make build arguments available in build stage (after FROM instruction)
# @see https://docs.docker.com/engine/reference/builder/#understand-how-arg-and-from-interact
ARG PHP_VERSION
ARG CRAFT_APP_TYPE
ARG CRAFT_VERSION

ARG PACKAGE_NAME
ENV PACKAGE_NAME=$PACKAGE_NAME

# Install additional PHP extensions here
# @link https://github.com/craftcms/docker/tree/7.3#installing-extensions
# USER root
# RUN docker-php-ext-install ...
# USER www-data

# Install composer from official image into this image
# @link https://hub.docker.com/_/composer/
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Bring over missing craftcms files
COPY --chown=www-data:www-data ./docker/craftcms/craft ./craft
COPY --chown=www-data:www-data ./docker/craftcms/craft_queue_listen.sh ./craft_queue_listen.sh
COPY --chown=www-data:www-data ./docker/craftcms/composer.json ./composer.json
COPY --chown=www-data:www-data ./docker/craftcms/web ./web

# Bring over test files
COPY --chown=www-data:www-data ./tests ./tests
COPY --chown=www-data:www-data ./codeception.yml ./codeception.yml

# Bring over package files into local packages directory
COPY --chown=www-data:www-data ./composer.json ./packages/${PACKAGE_NAME}/composer.json
COPY --chown=www-data:www-data ./src ./packages/${PACKAGE_NAME}/src

# @todo: install and run envsubst to
# @see https://github.com/nginxinc/docker-nginx/blob/f3fe494531f9b157d9c09ba509e412dace54cd4f/stable/alpine/Dockerfile#L87-L103
# @see https://nickjanetakis.com/blog/using-envsubst-to-merge-environment-variables-into-config-files
# @see https://github.com/nginxinc/docker-nginx/blob/f3fe494531f9b157d9c09ba509e412dace54cd4f/stable/alpine/20-envsubst-on-templates.sh
# RUN '{ \
#     "type": "path", \
#     "url": "./packages/$PACKAGE_NAME", \
#     "options": { \
#         "versions": { "$PACKAGE_NAME": "dev-master" } \
#     } \
# }' | envsubst | php composer config reepositories.0 -

# Install Craft-CMS, its dependencies and local package with composer
RUN composer require craftcms/cms:${CRAFT_VERSION} ${PACKAGE_NAME}:dev-master -w --no-install && \
    composer install --verbose --no-progress --optimize-autoloader --no-interaction --prefer-dist

