# Deploying IncidentFlow on a Hostinger VPS

Measured footprint: the whole nine-container stack idles at **238 MiB**. Sizing
is about peak, not idle — `pm.max_children = 20` with `memory_limit = 256M`
gives a realistic peak near 2 GB.

| Plan | RAM | Verdict |
|---|---|---|
| KVM 1 | 4 GB | Minimum. Set the replica counts to 1. |
| KVM 2 | 8 GB | Recommended. Room for the default 2 replicas. |

Hostinger's advertised price assumes 24–48 month prepayment and **renews
substantially higher**. Budget for the renewal rate.

---

## 1. Buy and create the VPS

1. VPS → KVM 1 or KVM 2.
2. **Location**: nearest your users. Singapore or India for South Asia.
3. **OS template**: `Ubuntu 24.04` — or `Ubuntu 24.04 with Docker`, which skips step 4.
4. **SSH key**, not a password. Paste your public key (`~/.ssh/id_ed25519.pub`);
   generate one with `ssh-keygen -t ed25519` if you have none.
5. Note the IPv4 address.

## 2. Point your domain at it

An `A` record for your domain → the VPS IP. Do this **before** starting the
stack: Caddy requests a certificate on first boot, and Let's Encrypt rate-limits
repeated failures for a domain that does not yet resolve.

```bash
dig +short incidents.example.com     # must print your VPS IP
```

## 3. Harden the box

```bash
ssh root@YOUR_IP

adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh && cp /root/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh

# Keys only, no root login.
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/;s/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable
apt update && apt install -y fail2ban && systemctl enable --now fail2ban
```

**Open a second terminal and confirm `ssh deploy@YOUR_IP` works before closing
the first.** A typo in `sshd_config` locks you out of your own server, and
recovery means Hostinger's browser console.

## 4. Install Docker (skip if you chose the Docker template)

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker deploy
newgrp docker
docker compose version
```

## 5. Fetch the code

```bash
sudo mkdir -p /opt/incidentflow && sudo chown deploy:deploy /opt/incidentflow
git clone https://github.com/iparthanth/IncidentFlow.git /opt/incidentflow
cd /opt/incidentflow
cp .env.prod.example .env && chmod 600 .env
```

## 6. Fill in the secrets

```bash
echo "base64:$(openssl rand -base64 32)"   # APP_KEY
openssl rand -base64 32                    # DB_PASSWORD
openssl rand -base64 32                    # REDIS_PASSWORD
nano .env
```

`DOMAIN`, `APP_URL` and `APP_FRONTEND_URL` must all be the **https://** form of
the same domain. On a 4 GB box leave the replica counts at 1.

Mailpit is disabled in production, so `MAIL_*` needs a real provider — Brevo,
Mailgun and Resend all have free tiers. Notifications queue and retry rather
than vanish if it is wrong, but they will not arrive.

## 7. Start it

```bash
cd /opt/incidentflow
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml \
  up -d --build --wait --wait-timeout 600
```

First run builds the images and takes several minutes. Then run the migrations
once — the entrypoint deliberately does not, so that replicas cannot race:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml \
  run --rm migrate
```

## 8. Create the first account

**There are no demo accounts in production.** `DatabaseSeeder` refuses to run
there, by design — seeding known credentials into a public deployment is a
breach with extra steps. Open `https://your-domain` and use **Create an
account**: the first registration creates the organization and makes you its
owner.

## 9. Verify

```bash
curl -sI https://your-domain | head -3                      # 200, and HTTPS
curl -s  https://your-domain/api/v1/health/ready            # {"status":"ready",...}
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml ps
```

Sign in, open a second browser, change an incident's status in one and watch the
other update without a refresh — that exercises Redis, Horizon and the SSE
stream in one action.

If sign-in works but you are logged out ~15 minutes later, TLS is not terminating
properly: the refresh cookie is `Secure`, so it is never sent back over plain
HTTP. Check `docker compose logs caddy` for a certificate failure.

## 10. Backups — do not skip this

The app's entire premise is a timeline nobody can rewrite. On a VPS that
timeline is on one disk with no managed snapshots.

```bash
sudo mkdir -p /var/backups/incidentflow && sudo chown deploy:deploy /var/backups/incidentflow
crontab -e
```

```cron
15 3 * * * /opt/incidentflow/scripts/backup-db.sh >> /var/log/incidentflow-backup.log 2>&1
```

Set `BACKUP_REMOTE` in `.env` to an rclone remote (Backblaze B2 is cheap) — a
backup on the same disk as the database only survives the failures that do not
matter.

**Test the restore now, not during an incident:**

```bash
gunzip -c /var/backups/incidentflow/incidentflow-*.sql.gz | head -40
```

## 11. Updating

```bash
cd /opt/incidentflow && git pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml \
  up -d --build --wait
docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml \
  run --rm migrate
```

Add `alias ifc='docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.tls.yml'`
to `~/.bashrc` so this stops being three flags every time.
