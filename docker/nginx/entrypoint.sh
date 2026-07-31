#!/bin/sh

ln -sf /app_node_modules/node_modules .
ln -sf /app_node_modules/package-lock.json .

# Substitute MIRADOR_CLIENT into the nginx config template.
# Only ${MIRADOR_CLIENT} is replaced; all nginx $variables are left intact.
# If MIRADOR_CLIENT is unset, the map entry becomes "" which never matches.
MIRADOR_CLIENT="${MIRADOR_CLIENT:-}" \
    envsubst '${MIRADOR_CLIENT}' \
    < /etc/nginx/conf.d/default.conf.template \
    > /etc/nginx/conf.d/default.conf

echo "executing \"$@\""
exec "$@"
