# First Build Mirador App
FROM node:14 AS build

RUN if [ "${TARGETARCH}" = "arm64" ]; then \
    apt-get update -y && apt-get install -y libpango1.0-dev \
    ; fi

ENV MODULES_DIR=/app_node_modules

WORKDIR /app

# copy package files FIRST - npm install layer only rebuilds if these change
COPY ./publink/mirador-annotations/package*.json ./

# combine all npm installs into one RUN - faster and single cache layer
# mirador-imagecropper is pinned to 0.1.9: 1.0.0+ dropped the "es/" ESM build
# that demo/src/index.js imports via 'mirador-imagecropper/es', so installing
# unpinned (latest) breaks the webpack build.
RUN npm install phantomjs-prebuilt@2.1.16 --ignore-scripts && \
    npm install mirador-imagecropper@0.1.9 && \
    npm install && \
    npm install react-draggable@4.4.3 && \
    rm -rf node_modules/react-rnd/node_modules/react-draggable && \
    cp -r node_modules/react-draggable node_modules/react-rnd/node_modules/react-draggable && \
    mkdir ${MODULES_DIR} && \
    mv node_modules ${MODULES_DIR}/ && \
    mv package-lock.json ${MODULES_DIR} && \
    ln -s ${MODULES_DIR}/* .

# copy source AFTER npm install - code changes won't bust the npm cache
COPY ./publink/mirador-annotations .

RUN npm run build

# Production Stage
FROM nginx:stable-alpine AS production

COPY --from=build /app /var/www/mirador

RUN mkdir /var/log/mirador
RUN rm /etc/nginx/conf.d/default.conf
COPY ./publink/html /var/www/html
COPY ./nginx/nginx.conf /etc/nginx/conf.d/default.conf.template
COPY ./nginx/entrypoint.sh /docker-entrypoint.d/40-envsubst-mirador.sh
RUN chmod +x /docker-entrypoint.d/40-envsubst-mirador.sh