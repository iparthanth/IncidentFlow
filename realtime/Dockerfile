# syntax=docker/dockerfile:1.7
#
# IncidentFlow realtime service.

# ---------------------------------------------------------------------------
# Stage 1 — dependencies and TypeScript build
# ---------------------------------------------------------------------------
FROM node:22-alpine AS build

WORKDIR /app

# Manifests first so the dependency layer is cached until they change.
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY tsconfig*.json ./
COPY src ./src

RUN npm run build

# A second, production-only install: the build needed TypeScript and the
# runtime does not, and shipping a compiler in a service image is both weight
# and attack surface.
RUN npm ci --omit=dev --no-audit --no-fund \
    && npm cache clean --force

# ---------------------------------------------------------------------------
# Stage 2 — runtime
# ---------------------------------------------------------------------------
FROM node:22-alpine AS runtime

RUN apk add --no-cache tini && rm -rf /var/cache/apk/*

ENV NODE_ENV=production

WORKDIR /app

COPY --from=build --chown=node:node /app/node_modules ./node_modules
COPY --from=build --chown=node:node /app/dist ./dist
COPY --from=build --chown=node:node /app/package.json ./package.json

# The base image ships an unprivileged `node` user; nothing here needs root.
USER node

EXPOSE 3001

# Liveness only — /healthz is dependency-free on purpose, so a Redis outage
# does not make the orchestrator restart every fan-out node and turn a degraded
# broker into a total outage.
HEALTHCHECK --interval=15s --timeout=3s --start-period=10s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3001)+'/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

# tini reaps zombies and forwards SIGTERM, which is what lets the graceful
# shutdown in src/index.ts actually run instead of being SIGKILLed.
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["node", "dist/index.js"]
