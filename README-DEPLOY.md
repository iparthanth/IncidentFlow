# Deploying IncidentFlow

The frontend and the backend go to different places, and there is a real reason
for that rather than a preference.

| Tier | Host | Why |
|---|---|---|
| React SPA | **Vercel** | Static build, global CDN, instant rollbacks |
| Laravel API | **Render** | Vercel has no PHP runtime |
| Realtime (SSE/WS) | **Render** | Serverless functions can't hold long-lived connections |
| PostgreSQL, Redis | **Render** | Managed, and adjacent to the services that use them |

Order matters: **the backend must exist before the frontend is deployed**, because
the frontend's `vercel.json` rewrites `/api` and `/realtime` at the edge to real
hostnames. Deploying the SPA first gives you a login screen that cannot log in.

---

## Step 1 — Backend on Render

### 1.1 Create the services

Render Dashboard → **New** → **Blueprint** → connect `iparthanth/IncidentFlow`.
Render reads [`render.yaml`](render.yaml) and creates:

- `incidentflow-db` — PostgreSQL
- `incidentflow-redis` — Key Value store
- `incidentflow-api` — Laravel (nginx + PHP-FPM, `render` Dockerfile stage)
- `incidentflow-realtime` — Node SSE/WebSocket service

### 1.2 Supply the secrets

Render will prompt for every variable marked `sync: false`. All of them have
already been generated for you in **`deploy-secrets.local.txt`** in the project
root — that file is gitignored and never leaves your machine.

| Variable | Service | Source |
|---|---|---|
| `APP_KEY` | api | `deploy-secrets.local.txt` |
| `JWT_PRIVATE_KEY` | api **only** | `deploy-secrets.local.txt` |
| `JWT_PUBLIC_KEY` | api **and** realtime | `deploy-secrets.local.txt` |
| `APP_URL` | api | The URL Render assigns the API |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` | api | Your Vercel URL (fill in after step 2) |
| `CORS_ORIGINS` | realtime | Your Vercel URL (fill in after step 2) |

> The **same** `JWT_PUBLIC_KEY` must go to both services. The API signs with the
> private half; the realtime service verifies with the public half and can never
> mint a token of its own. Mismatched keys look like a broken realtime tier
> rather than a configuration error, so it is worth double-checking.

To regenerate the pair later:

```bash
php artisan jwt:keys --force --path=/tmp/keys
```

### 1.3 Run the migrations once

The container entrypoint deliberately does **not** migrate — a migration racing
across replicas leaves a half-applied schema. Run it manually, once, from the
Shell tab on `incidentflow-api`:

```bash
php artisan migrate --force --seed
```

### 1.4 Verify

```bash
curl https://incidentflow-api.onrender.com/api/v1/health/ready
curl https://incidentflow-realtime.onrender.com/readyz
```

Both should report `ready`. If the API says `"degraded": true`, Redis is not
reachable — check `REDIS_URL` on the API service.

---

## Step 2 — Frontend on Vercel

### 2.1 Point the rewrites at the real backend

Edit [`web/vercel.json`](web/vercel.json) and replace the two placeholders with
the hostnames Render gave you:

```json
{ "source": "/api/:path*",      "destination": "https://incidentflow-api.onrender.com/api/:path*" },
{ "source": "/realtime/:path*", "destination": "https://incidentflow-realtime.onrender.com/:path*" }
```

Rewrites rather than a `VITE_API_URL` are deliberate: they keep the browser
talking to a single origin, exactly as nginx does locally. That means no CORS
preflight on every request, and the refresh cookie stays same-site.

### 2.2 Deploy

```bash
cd web
npx vercel --prod
```

Set **Root Directory** to `web` if you connect the Git repository through the
dashboard instead.

### 2.3 Close the loop

Put the resulting Vercel URL into the three backend variables left blank in
step 1.2 (`FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ORIGINS`) and
redeploy the two Render services.

---

## Free-tier caveats

Worth knowing before you demo this to anyone:

- **Services sleep after 15 minutes idle** and take roughly 30 seconds to wake.
  The first request after a quiet spell looks like a timeout. Open the API URL
  yourself a minute before showing anyone.
- **No background workers on free.** The Horizon worker is commented out in
  `render.yaml`. Everything works except that queued email notifications are
  prepared and never delivered. `MAIL_MAILER=log` reflects this honestly rather
  than pretending mail was sent.
- **Free Postgres expires after 30 days.** Fine for a portfolio demo; take a
  `pg_dump` if the data matters.

## Running it without any of this

The whole stack runs locally with `docker compose up --build`, or natively with
no Docker at all. Both paths are documented step by step in
[`docs/running-incidentflow.pdf`](docs/running-incidentflow.pdf).
