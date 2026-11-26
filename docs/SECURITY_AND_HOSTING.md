# Security Hardening and Hosting Guide (Chama Core)

This document covers production hosting options and a comprehensive security checklist for this Laravel application. "Completely secure" is an ongoing process (configuration, infrastructure, patching, monitoring, and operations all matter), but following this guide will put the system in a strong, production-ready security posture.

## 1) Current security controls in the application

The codebase already includes important controls:

- **Authentication & sessions**: Laravel session auth, session regeneration on login/logout, MFA middleware (`mfa`), active-account checks, password reset flow with token revocation of API tokens on reset.
- **Rate limiting**: login/password reset/invitation/employer-finance routes are throttled; API throttling is configured; `login` limiter is `5/min` per email+IP.
- **Password policy**: stronger defaults in production (`min 12`, mixed case, numbers, symbols, uncompromised check) via `Password::defaults()`.
- **Tenant isolation**: `SetGroupContext` resolves/verifies group membership (never trusts client group without membership checks), applies plan/module and portal-section restrictions.
- **Authorization**: policies/gates and permission checks (e.g. document access checks group, member ownership/visibility, staff permissions).
- **CSRF protection**: standard Laravel web middleware/CSRF for state-changing web routes (no custom CSRF exceptions found).
- **File upload validation**: server-side size/type checks, random stored filenames, advance/signature handling validates PNG signatures (`App\Support\SignatureCapture`) and stores sensitive documents on the non-public `documents` disk (`storage/app/documents`).
- **Safe rendering**: no `{!! !!}` unescaped output found in app/views (Blade escaping by default reduces XSS risk).
- **Secure document storage/serving**: private documents are not served from `public/`; downloads go through controllers/services with authorization and access logging.
- **HTTP hardening (added)**: global `SecurityHeaders` middleware now sets `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `X-Permitted-Cross-Domain-Policies`, `Permissions-Policy`, disables shared-cache storage for authenticated responses, and sends HSTS on secure requests.
- **Reverse proxy support**: trusted proxies configurable (`TRUSTED_PROXIES`) and HTTPS scheme forced in production.
- **Signed, time-bound external actions**: employer finance confirmation routes use signed URLs with throttling.

## 2) Hosting options

### Option A (recommended): Docker on a VPS/cloud server

This repo already includes a production-oriented Docker stack: `Dockerfile`, `docker-compose.yml`, `docker/nginx/default.conf`, and PHP-FPM config (Nginx + PHP-FPM app + MySQL + Redis + queue + scheduler).

**High-level steps (Linux VPS, e.g. Ubuntu):**
1. Provision a server (2+ vCPU, 4GB+ RAM recommended), create a non-root sudo user, and enable a firewall (allow SSH, 80, 443 only).
2. Install Docker Engine + Docker Compose plugin. Keep the OS and Docker updated.
3. Copy the application to the server (git clone or secure file transfer). Do **not** copy local `.env` secrets from dev machines.
4. Create production `.env` from `.env.production.example`:
   - Set `APP_ENV=production`, `APP_DEBUG=false`, correct `APP_URL=https://your-domain`.
   - Run `php artisan key:generate` (inside container or locally with PHP/composer available) and set a strong `APP_KEY`.
   - Set strong, unique passwords/secrets for DB, Redis (if exposed), mail, admin, and all integrations.
   - Set `CHAMA_SEED_DEMO=false`.
   - Set `TRUSTED_PROXIES` to your proxy IP/CIDR (or `*` only if the app is reachable only via that proxy).
   - Set `SESSION_SECURE_COOKIE=true` and `SESSION_ENCRYPT=true` (with HTTPS).
5. Put TLS/HTTPS in front of the stack (recommended): run a reverse proxy such as Caddy, Traefik, or host Nginx with Let's Encrypt and forward to the compose `web` service (`APP_PORT`, default mapping). Terminate TLS at the proxy and keep internal traffic private.
6. Start the stack:
   - `docker compose up -d --build`
   - The entrypoint can run migrations/optimize when enabled (`RUN_MIGRATIONS=true`, `RUN_OPTIMIZE=true`).
7. Create/verify the initial platform admin user securely (use seeder/provisioning flow as designed; rotate default credentials immediately).
8. Verify: login, MFA (if enabled), member portal, loan/advance flows, document uploads/downloads, employer remittance PDF/upload, queue processing, scheduler jobs, backups.

**Production notes for this compose stack:**
- Keep MySQL/Redis **not publicly exposed** (bind to internal network only; do not publish their ports to the internet).
- Use persistent volumes (already defined) and back them up.
- Ensure `storage/` and `bootstrap/cache/` are writable by the app container; do not make them web-served except intended public storage.

### Option B: Local/LAN access from this Windows machine (quick/internal use)

This is suitable only for trusted internal networks, not the public internet.

1. Use a production-like env for safety: `APP_ENV=production`, `APP_DEBUG=false`, set `APP_URL` to the machine's LAN URL/IP, generate `APP_KEY`, use strong admin credentials, disable demo seed.
2. Prefer running behind a proper web server with HTTPS if possible. If using `php artisan serve`, bind to LAN interface only as needed and understand it is not a hardened production server.
3. Restrict access with Windows Firewall to trusted IP ranges/subnets; do not port-forward from the public internet.
4. Use a proper database for multi-user reliability if moving beyond local testing (SQLite is limited for concurrency).
5. Protect the machine (updates, disk encryption, endpoint protection, locked accounts) because it hosts sensitive financial/member data.

### Option C: Shared/cPanel PHP hosting (no Docker)

Possible for Laravel apps, but requires adapting this setup (this project currently runs locally on SQLite and includes Docker-first infra).

1. Requirements: PHP 8.3+, required extensions (`bcmath`, `json`, `pdo_mysql`, `zip`, `gd`, `intl`, `mbstring`, `xml`, etc.), Composer on deploy/build, MySQL/MariaDB, HTTPS, cron, and ideally a queue worker/supervisor (or switch to a host-supported worker strategy).
2. Set document root to `public/` (never expose project root). Ensure `.env`, `vendor/`, `storage/`, and app code are outside web root access.
3. Configure `.env` for production (`APP_DEBUG=false`, correct `APP_URL`, DB credentials, mail, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` if behind host proxy/CDN).
4. Run deploy steps on server/build: `composer install --no-dev --optimize-autoloader`, `php artisan key:generate` (once), `php artisan migrate --force`, `php artisan storage:link` only if public storage is needed, `php artisan config:cache route:cache view:cache`.
5. Set permissions: writable `storage/` and `bootstrap/cache/`; protect `.env` (e.g. 600/640 as applicable).
6. Configure cron for scheduler (`* * * * * php /path/to/artisan schedule:run`) and a reliable queue worker.
7. Confirm private `documents` storage is not publicly accessible via the web server.

## 3) Comprehensive security checklist

### Application & configuration
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, correct `APP_URL` (https).
- [ ] Strong unique `APP_KEY`; rotate/re-encrypt strategy planned if key compromise is suspected.
- [ ] All secrets (DB, mail, Redis, M-Pesa/bank/SMS/WhatsApp/FCM, admin) are strong, unique, and stored only in server env/secret manager.
- [ ] `CHAMA_SEED_DEMO=false` in production; remove/disable demo accounts and default credentials.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax` (or `strict` if compatible) over HTTPS.
- [ ] `TRUSTED_PROXIES` set correctly so HTTPS, client IPs, and signed/URL generation work securely behind a proxy.
- [ ] Logging level appropriate (`warning`/`error` in prod) and logs protected from public access; no sensitive PII/secrets logged unnecessarily.
- [ ] CORS/stateful domains (`SANCTUM_STATEFUL_DOMAINS`) restricted to trusted origins only.

### Authentication, authorization & data access
- [ ] Enforce MFA for privileged/staff accounts where supported; protect admin accounts with strongest passwords and least privilege.
- [ ] Verify role/permission assignments (least privilege), including who can view/download/manage documents and approve/disburse loans.
- [ ] Confirm tenant isolation on every sensitive endpoint (group context, policies, and query scoping) and test cross-tenant access attempts.
- [ ] Keep password reset, invitations, and signed finance-confirmation links time-limited, throttled, and monitored.

### File uploads & document handling (important for advance/remittance flows)
- [ ] Accept only required file types/sizes; keep server-side validation as the source of truth.
- [ ] Store sensitive documents (payslips, employment letters, signatures, stamped employer forms) on private/non-public storage and serve only via authorized controllers.
- [ ] Ensure uploaded files are never executed/interpreted by the web server; disable script execution in upload/storage paths.
- [ ] Consider malware scanning for uploads in high-risk environments; retain access/audit logs for document actions.
- [ ] Validate and constrain signature/image payloads (size, format, decoding) as currently implemented.

### Transport, headers & browser security
- [ ] Enforce HTTPS everywhere (TLS 1.2+), redirect HTTP to HTTPS, and enable HSTS (sent on secure requests by app; can also be enforced at proxy).
- [ ] Keep security headers enabled (app middleware + web server) and review CSP needs before tightening to avoid breakage.
- [ ] Use secure cookies and protect against clickjacking/CSRF/MIME sniffing (in place; verify in deployed environment).

### Infrastructure & server hardening
- [ ] Firewall: expose only required ports (typically 80/443 and restricted SSH). Do not expose DB/Redis directly to the internet.
- [ ] Keep OS, Docker images, PHP, web server, DB, and dependencies patched; rebuild images regularly.
- [ ] Run services with least privilege; isolate app, DB, and cache networks.
- [ ] Disable directory listing and block access to dotfiles/sensitive paths (`.env`, `.git`, etc.).
- [ ] Set correct filesystem permissions/ownership; backups and storage volumes encrypted/protected where possible.
- [ ] For Docker, pin/update base images, scan images, and avoid running unnecessary privileged containers.

### Dependency & application security maintenance
- [ ] Run `composer audit` (and frontend dependency audits if applicable) and patch vulnerabilities promptly.
- [ ] Keep Laravel/framework dependencies up to date on supported/security-patched versions.
- [ ] Review custom queries/authorization/file handling when adding features; maintain tests for security-critical flows.

### Monitoring, backups & operations
- [ ] Automated, tested backups for database and uploaded documents/storage; store backups securely off-host with retention (`BACKUP_RETENTION_DAYS`) and periodic restore drills.
- [ ] Monitor logs/alerts for auth failures, 403/429 spikes, suspicious uploads/downloads, and payment/callback anomalies.
- [ ] Audit trails: retain and review login, document access, and financial/loan action logs.
- [ ] Incident response: plan for key/credential rotation, account lock/disable, and breach containment.

### Pre-go-live verification
- [ ] Confirm `APP_DEBUG=false` and debug pages/stack traces are not exposed.
- [ ] Verify HTTPS, secure cookies, headers, and correct `APP_URL`/redirects.
- [ ] Test authz boundaries (member vs staff vs admin, cross-group/cross-member access denied).
- [ ] Test uploads/downloads (allowed/blocked types, size limits, authorized access only).
- [ ] Test advance/remittance flows end-to-end (docs, signature, PDF, stamped upload/confirm, status transitions).
- [ ] Test rate limiting, password reset, and session/logout behavior.

## 4) Commands useful during secure deployment

```bash
# install deps (production)
composer install --no-dev --optimize-autoloader

# one-time app key
php artisan key:generate --force

# database
php artisan migrate --force

# optimize (after env/config finalized)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# dependency security check
composer audit
```

> Run commands in the correct environment/container and ensure `.env` is configured before caching config/routes.
