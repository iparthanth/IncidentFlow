# syntax=docker/dockerfile:1.7
#
# IncidentFlow SPA.
#
# The output is a handful of static files. They are baked into an nginx image
# rather than served by Node, because a single-page app has no server-side
# work to do and every process you do not run is a process that cannot break.

FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY . .

# Vite inlines VITE_* at build time, so anything configurable per environment
# must be a build argument — there is no runtime config for a static bundle.
# (The API base path is same-origin by design, so there is very little here.)
ARG VITE_APP_VERSION=dev
ENV VITE_APP_VERSION=${VITE_APP_VERSION}

RUN npm run build

# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS runtime

# The SPA's own server block. The reverse proxy in front of it owns routing to
# the API and the realtime service; this one only serves files.
COPY --from=build /app/dist /usr/share/nginx/html
COPY docker/nginx-spa.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=3s --retries=3 \
    CMD wget -q --spider http://127.0.0.1/ || exit 1
