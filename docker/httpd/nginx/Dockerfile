ARG NGINX_VERSION=latest

FROM nginx:${NGINX_VERSION}-alpine

ARG NGINX_VERSION
ARG NGINX_ENVSUBST_TEMPLATE_SUFFIX=.template
ARG NGINX_ENVSUBST_TEMPLATE_DIR=/etc/nginx/templates

ENV NGINX_VERSION=${NGINX_VERSION}
ENV NGINX_ENVSUBST_TEMPLATE_SUFFIX=${NGINX_ENVSUBST_TEMPLATE_SUFFIX}
ENV NGINX_ENVSUBST_TEMPLATE_DIR=${NGINX_ENVSUBST_TEMPLATE_DIR}

# Copy custom config template files into the container's `/etc/nginx/templates`
# directory, where they will be picked up by docker which will:
# - replace references to environment variables using `envsubst`
# - remove the `.template` extension
# - put the resulting config file in `/etc/nginx/conf.d`
COPY ./docker/httpd/nginx/includes/*.conf${NGINX_ENVSUBST_TEMPLATE_SUFFIX} ${NGINX_ENVSUBST_TEMPLATE_DIR}/includes/
COPY ./docker/httpd/nginx/*.conf${NGINX_ENVSUBST_TEMPLATE_SUFFIX} ${NGINX_ENVSUBST_TEMPLATE_DIR}/

# Copy custom NGINX config files into container
COPY ./docker/httpd/nginx/includes/*.conf /etc/nginx/conf.d/includes/
COPY ./docker/httpd/nginx/*.conf /etc/nginx/conf.d/

COPY --chown=www-data:www-data ./docker/craftcms/web /app/web
