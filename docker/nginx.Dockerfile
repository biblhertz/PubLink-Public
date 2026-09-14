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

# Mirador 3.x's asArray() only treats `undefined` as "empty", not `null`. When a
# manifest omits an optional property (e.g. requiredStatement), manifesto.js's
# getter returns null, so asArray(null) becomes [null] instead of [] — and
# selectors like getRequiredStatement() then crash calling .getValues() on that
# null entry (TypeError: Cannot read properties of null (reading 'getValues')).
# Fixed upstream in Mirador 4.x (optional chaining), but 4.x is a breaking
# change for our custom annotation plugin/mirador-imagecropper pin, so patch
# just this one function in the installed 3.x package instead of upgrading.
RUN sed -i 's/if (value === undefined) return \[\];/if (value === undefined || value === null) return [];/' \
    node_modules/mirador/dist/es/src/lib/asArray.js \
    node_modules/mirador/dist/cjs/src/lib/asArray.js

# copy source AFTER npm install - code changes won't bust the npm cache
COPY ./publink/mirador-annotations .

RUN npm run build

# Production Stage
FROM nginx:stable-alpine AS production

COPY --from=build /app /var/www/mirador

RUN mkdir /var/log/mirador
RUN rm /etc/nginx/conf.d/default.conf
COPY ./publink/html /var/www/html

# AdminLTE 3.2.0 (dist + plugins) is fetched at build time instead of being
# vendored in the repo — the npm package only ships dist/, so we pull the
# full GitHub source tag, which is what the project's own repo commits.
RUN apk add --no-cache curl tar && \
    mkdir -p /var/www/html/adminLTE && \
    curl -fsSL https://github.com/ColorlibHQ/AdminLTE/archive/refs/tags/v3.2.0.tar.gz | tar -xz -C /tmp && \
    cp -r /tmp/AdminLTE-3.2.0/dist /tmp/AdminLTE-3.2.0/plugins /var/www/html/adminLTE/ && \
    rm -rf /tmp/AdminLTE-3.2.0 && \
    apk del curl tar
COPY ./nginx/nginx.conf /etc/nginx/conf.d/default.conf.template
COPY ./nginx/entrypoint.sh /docker-entrypoint.d/40-envsubst-mirador.sh
RUN chmod +x /docker-entrypoint.d/40-envsubst-mirador.sh