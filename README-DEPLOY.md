# Deploying IncidentFlow

## Railway (current target — one project, nothing sleeps)

Everything runs in a single Railway project: Postgres, Redis, and five services.
`web` is the only one with a public domain; it serves the SPA *and* proxies
`/api`, `/horizon`, `/realtime` and `/ws` to its siblings over Railway's private
network. One origin, because the refresh token is an httpOnly cookie and two
domains would make it cross-site.

| Service     | Root       | Config                        | Public |
|-------------|------------|-------------------------------|--------|
| `web`       | `web/`     | `railway.json`                | yes    |
| `api`       | `api/`     | `railway.json`                | no     |
| `horizon`   | `api/`     | `railway.horizon.json`        | no     |
| `scheduler` | `api/`     | `railway.scheduler.json`      | no     |
| `realtime`  | `realtime/`| `railway.json`                | no     |

`horizon` and `scheduler` build the same image as `api` and differ only in
start command, so their Railway services point at `api/` with the **config file
path** set to the file above.

### Never sleeping

Every `railway.json` sets `deploy.sleepApplication: false` and
`restartPolicyType: ALWAYS`. This is not a preference. A slept service stops the
queue worker, stops the scheduler, and drops every open SSE connection — the
three things this architecture exists to provide. Do not enable app sleeping to
save credit; move to a cheaper plan tier instead.

### Two things that fail silently if skipped

**1. IPv6.** Railway's private DNS (`*.railway.internal`) resolves to IPv6 only.
A service listening on IPv4 alone is unreachable from a sibling no matter how
correct the hostname is. The api's nginx template now binds both stacks; the
realtime service needs `HOST=::` set as a variable (its default is `0.0.0.0`).

**2. JWT keys must be injected, not generated.** In Docker Compose, `api`,
`horizon` and `scheduler` share one `jwt-keys` volume. On Railway they are three
separate containers with no shared filesystem, so each would generate its *own*
key pair on boot and tokens minted by one would fail verification in another.
Generate the pair once and set it as variables:

```bash
php artisan jwt:keys --force --show
```

Set `JWT_PRIVATE_KEY` on `api`, `horizon` and `scheduler`; set `JWT_PUBLIC_KEY`
on all of those plus `realtime`. Leave `JWT_PRIVATE_KEY_PATH` unset — a value
in `JWT_PRIVATE_KEY` takes precedence over the file path.

### Variables

`api`, `horizon`, `scheduler` share: `APP_KEY`, `APP_ENV=production`,
`APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `PORT=8080`, `JWT_PRIVATE_KEY`,
`JWT_PUBLIC_KEY`, `APP_URL`/`FRONTEND_URL` (the `web` domain), plus
`DB_*` and `REDIS_*` from Railway's Postgres and Redis references.

`realtime`: `PORT=3001`, `HOST=::`, `JWT_PUBLIC_KEY`, Redis reference,
and the `web` domain as its allowed origin.

`web`: `API_INTERNAL=api.railway.internal:8080`,
`REALTIME_INTERNAL=realtime.railway.internal:3001`.

### Migrations

The container entrypoint deliberately does not migrate — see the comment at the
top of `api/docker/entrypoint.sh`. Run it once, by hand, after the first deploy:

```bash
railway run --service api php artisan migrate --force --seed
```

### Requires a paid plan

Provisioning this project on a free Railway workspace fails with *"Free plan
resource provision limit exceeded"*. Always-on services are a paid-plan
property, so the never-sleep requirement and the free tier are mutually
exclusive by definition.

---


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
