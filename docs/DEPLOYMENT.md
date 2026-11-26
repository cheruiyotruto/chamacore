# Deployment

Chama Core is a standard Laravel 12 application. It needs a PHP 8.3 runtime, a
MySQL-compatible database, a queue worker and the scheduler. This guide covers
the two supported deployment paths and the checks that apply to both.

## 1. Pre-flight checklist

Complete these before exposing the application to members:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY` and a real
      `APP_URL` using HTTPS.
- [ ] MySQL 8.0+ (or MariaDB 10.6+) with a dedicated database and user.
- [ ] PHP extensions: `bcmath`, `json`, `pdo_mysql`, `zip`, `mbstring`, `dom`,
      `gd` and `intl`. The Docker image installs all of them.
- [ ] `storage/` and `bootstrap/cache/` writable by the web-server user.
- [ ] `CHAMA_SEED_DEMO=false`. Never expose the demo tenant in production.
- [ ] Platform administrator password changed after the first login; MFA enabled.
- [ ] M-Pesa callback URL and secret configured if payments are enabled.
- [ ] Scheduler and queue worker running (see below).
- [ ] Encrypted database and document backups with a tested restore procedure.
- [ ] `php artisan chama:reconcile-ledgers --strict` returns success after the
      first financial posting.

Generate an application key on a machine without PHP with:

```bash
echo "base64:$(openssl rand -base64 32)"
```

## 2. Docker Compose on a VPS

The repository ships `Dockerfile` and `docker-compose.yml` with these services:

| Service | Role |
|---|---|
| `app` | PHP-FPM application container |
| `nginx` | Web server, publishes `${APP_PORT}` (default `8080`) |
| `queue` | `queue:work` worker for notifications and exports |
| `scheduler` | `schedule:work` for the commands in `routes/console.php` |
| `mysql` | MySQL 8.4 with a persistent volume |
| `redis` | Cache, sessions and queue backend |

### 2.1 Prepare the host

Install Docker Engine 24+ with the Compose v2 plugin, point the domain's DNS at
the host and open only ports 22, 80 and 443 in the firewall. 2 GB of RAM is a
sensible minimum for the full stack.

### 2.2 Configure the environment

```bash
cp .env.example .env
```

Edit `.env` and set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://chama.example.com
APP_KEY=base64:...
APP_PORT=8080

CHAMA_SEED_DEMO=false
PLATFORM_ADMIN_EMAIL=owner@example.com
PLATFORM_ADMIN_PASSWORD="a-long-random-password"

DB_DATABASE=chama_core
CHAMA_DB_USER=chama
CHAMA_DB_PASSWORD="a-long-random-password"
MYSQL_ROOT_PASSWORD="another-long-random-password"

SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=chama.example.com
TRUSTED_PROXIES=*
```

The containers ignore `DB_HOST`, `DB_USERNAME` and `DB_PASSWORD` from `.env`;
Compose rewrites those to the `mysql` service and the `CHAMA_DB_*` values. Leave
`CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` as they are: Compose
overrides them with Redis. If Redis is unavailable on the host, delete the
`redis` service, its `depends_on` entries and the `REDIS_*` overrides, then
restore the `database` values in the shared `environment` block instead.

### 2.3 Build and start

```bash
docker compose build
docker compose up -d
```

Wait for the MySQL health check, then create the schema and seed the platform
administrator:

```bash
docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan optimize
docker compose restart app queue scheduler
```

Open `https://chama.example.com` through your TLS proxy, or
`http://server-ip:8080` while testing. The Laravel health endpoint is `/up`.

### 2.4 TLS

Terminate TLS in front of the `nginx` container. With Caddy on the host:

```caddyfile
chama.example.com {
    encode zstd gzip
    reverse_proxy 127.0.0.1:8080
}
```

With host Nginx and Certbot, proxy to `http://127.0.0.1:8080` and keep the
`Host`, `X-Forwarded-For` and `X-Forwarded-Proto` headers. Only enable HSTS in
`docker/nginx/default.conf` once HTTPS is permanent.

### 2.5 Day-to-day operations

```bash
docker compose ps
docker compose logs -f app queue scheduler
docker compose exec app php artisan chama:reconcile-ledgers --strict
docker compose exec app php artisan schedule:list
docker compose exec app php artisan queue:failed
```

Scale queue throughput without starting a second scheduler:

```bash
docker compose up -d --scale queue=3
```

The scheduler must run exactly once; `schedule:work` already tracks due events
inside the single process. If you prefer cron over the `scheduler` service, stop
that service and add:

```cron
* * * * * cd /srv/chama-core && docker compose exec -T app php artisan schedule:run >> /dev/null 2>&1
```

### 2.6 Updates

```bash
docker compose exec mysql sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > backup-$(date +%F).sql
docker compose build --pull
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
docker compose restart app queue scheduler
docker compose exec app php artisan chama:reconcile-ledgers --strict
```

Because OPcache validates timestamps only at process start, the restart is
required after every code update. Pinning a release tag instead of
`chama-core:latest` makes rollback a matter of redeploying the previous tag
together with the matching database backup.

### 2.7 Backups

Back up three things together: the database, the storage volume and `APP_KEY`.
Without `APP_KEY`, encrypted columns and sessions cannot be recovered.

```bash
docker compose exec mysql sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines "$MYSQL_DATABASE"' | gzip > chama-db-$(date +%F).sql.gz

docker run --rm -v chama-core_storage:/data -v "$PWD":/backup alpine \
    tar czf /backup/chama-storage-$(date +%F).tar.gz -C /data .
```

Copy archives off the host, encrypt them and test a restore into a staging
stack at least monthly. The volume name is prefixed with the Compose project
name (`chama-core` by default).

## 3. cPanel and shared hosting

Shared hosting works for small groups if the host provides PHP 8.3 with the
required extensions and cron.

1. Upload the application **outside** `public_html`, for example
   `~/chama-core`, then point the domain or subdomain document root at
   `~/chama-core/public`.
2. If the document root cannot be changed, keep `public/` as the web root and
   add a rewrite in the account's root `.htaccess`:

   ```apache
   RewriteEngine On
   RewriteRule ^(.*)$ public/$1 [L]
   ```

3. Run `composer install --no-dev --optimize-autoloader` from cPanel Terminal,
   or upload a `vendor/` directory built for PHP 8.3.
4. Create the database and user in cPanel, then set the credentials in `.env`.
5. Set permissions: directories `755`, `storage/` and `bootstrap/cache/`
   writable by the account (`775` is usually enough), files `644`.
6. Run:

   ```bash
   php artisan key:generate
   php artisan migrate --seed --force
   php artisan storage:link
   php artisan optimize
   ```

7. Add the scheduler cron entry (every minute):

   ```cron
   /usr/local/bin/php /home/ACCOUNT/chama-core/artisan schedule:run >> /home/ACCOUNT/chama-core/storage/logs/scheduler.log 2>&1
   ```

8. Without Supervisor, run the queue from cron every minute. The
   `--stop-when-empty` and `--max-time` flags prevent overlapping workers:

   ```cron
   * * * * * /usr/local/bin/php /home/ACCOUNT/chama-core/artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /home/ACCOUNT/chama-core/storage/logs/queue.log 2>&1
   ```

   Very small installations may set `QUEUE_CONNECTION=sync`, but payment
   callbacks and notifications then run inside the HTTP request and can time
   out. Prefer the cron worker.

9. Enable AutoSSL and force HTTPS. Set `SESSION_SECURE_COOKIE=true` and add the
   domain to `SANCTUM_STATEFUL_DOMAINS`.

`public/.htaccess` is included and forwards the `Authorization` header, which
Sanctum bearer tokens require on Apache. The bundled `docker/nginx/default.conf`
is not used by cPanel.

## 4. Plain VPS without Docker

Install Nginx, PHP 8.3-FPM, Composer and MySQL 8, then deploy the code to
`/srv/chama-core`. Install `php8.3-redis` if you use Redis for the cache, queue
and sessions; otherwise keep the `database` drivers in `.env`. A minimal server
block:

```nginx
server {
    listen 443 ssl http2;
    server_name chama.example.com;
    root /srv/chama-core/public;

    # ssl_certificate ...; ssl_certificate_key ...;

    client_max_body_size 24m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Run the queue as a systemd service:

```ini
# /etc/systemd/system/chama-queue.service
[Unit]
Description=Chama Core queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/srv/chama-core
ExecStart=/usr/bin/php /srv/chama-core/artisan queue:work --tries=3 --timeout=120 --sleep=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now chama-queue
```

Use the cron entry from section 3 for the scheduler, replacing the path with
`/srv/chama-core`.

## 5. Production environment reference

| Variable | Production value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://chama.example.com` |
| `APP_KEY` | fresh `base64:` key, backed up |
| `LOG_LEVEL` | `warning` or `error` |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_PROXIES` | `*` behind a TLS proxy, otherwise the proxy IPs/CIDRs |
| `SESSION_DRIVER` | `redis` or `database` |
| `CACHE_STORE` | `redis` or `database` |
| `QUEUE_CONNECTION` | `redis` or `database` |
| `CHAMA_SEED_DEMO` | `false` |
| `MAIL_MAILER` | `smtp` or a transactional API |
| `SMS_DRIVER` | the live provider driver, not `log` |
| `MPESA_ENVIRONMENT` | `production` |
| `BACKUP_RETENTION_DAYS` | 30 or the group's policy |

## 6. Post-deployment smoke test

```bash
curl -fsS https://chama.example.com/up
php artisan schedule:list
php artisan chama:reconcile-ledgers --strict
php artisan queue:monitor --max=100
```

Then verify in the browser:

1. Platform administrator login and MFA setup.
2. Group creation, member approval and a small savings deposit.
3. A wallet top-up in the sandbox (or a manual payment) and its ledger entry.
4. A loan quote, application, approval and repayment schedule.
5. Member portal login with an ordinary member account.
6. API login, `X-Group-Id` request and a 403 when the header is missing.
7. Notification delivery and a document download.
8. Database and storage restore into a staging copy.

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `500` with blank page | `APP_KEY` missing, `storage/` unwritable | check `storage/logs/laravel.log`, permissions |
| `502` from proxy | PHP-FPM container down | `docker compose ps`, `docker compose logs app` |
| `SQLSTATE[HY000] [2002]` | wrong database host or MySQL not ready | wait for health check, verify `DB_HOST` |
| Scheduler events never run | cron missing or `schedule:work` container stopped | `php artisan schedule:list`, check logs |
| Jobs stay queued | worker not running or wrong queue connection | `docker compose logs queue`, `php artisan queue:failed` |
| `419 Page Expired` behind proxy | session cookie/domain mismatch | set `APP_URL`, `SESSION_DOMAIN`, secure cookie |
| API returns `401` with a valid token | missing `X-Group-Id` or group ability | log in again with the correct group |
| Uploaded files return `404` | storage link or `/storage/` alias missing | `php artisan storage:link` or check Nginx alias |
| Ledger reconciliation reports a difference | interrupted posting or manual SQL | run with `--strict`, investigate before new deposits |

## 8. Security hardening

- Publish only Nginx (and the TLS proxy). MySQL and Redis stay on the internal
  Docker network; never map port 3306 or 6379 publicly.
- Keep `.env`, backups and database dumps outside the web root and restrict
  access to them.
- Use a unique database password and rotate the platform administrator
  credentials after seeding.
- Enforce MFA for staff roles from the governance settings.
- Run `chama:reconcile-ledgers --strict` after every deployment and investigate
  any discrepancy before accepting deposits.
- Review `audit_logs` and failed jobs weekly.
- Keep PHP, MySQL, Redis, Nginx and Composer dependencies patched; rebuild the
  image with `--pull` on a schedule.
