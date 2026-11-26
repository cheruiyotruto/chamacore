# Chama Core

Chama Core is a multi-tenant group-management platform for Kenyan chamas, SACCO-style
savings groups and table-banking circles. It combines member records, savings, a
merry-go-round, loans, wallets, M-Pesa payments, member/customer bills, bank
transfers and M-Pesa payouts, configurable payment charges, double-entry
accounting, meetings, governance, documents,
notifications, date reminders, isolated organisation branches, platform-managed
tenants and SaaS subscriptions.

The public marketing site, administration portal and member self-service portal
are server-rendered with Laravel Blade, Alpine.js and Tailwind CSS. A versioned
JSON API under `/api/v1` is available for the Flutter client.

## Public website

The public site is served from `/` and includes problem-led home, feature,
pricing, about and contact pages. Pricing cards are generated from active
`Plan` rows, so platform plan edits appear on the public site automatically.

Organisations use **Request access** at `/contact` to submit an access request.
Requests are stored in `marketing_leads` and reviewed by super admins at
**Platform → Access requests**. Existing users sign in at `/login`; the
authenticated dashboard is at `/dashboard` while keeping the `dashboard` route
name for redirects.

### Public member sign-up

People who want to join a managed group browse **Join a group** at `/join`.
The directory is opt-in: a super admin enables it per group under
**Platform → Groups → {group} → Public member sign-up** and can add a short
summary shown on the card. Only active head offices in `oversight` or `full`
platform mode can be listed; branches, suspended groups and self-managed groups
never appear. The toggle is audit logged. The same page also accepts an
invitation link or code, so invite-only and open applications share one entry
point and the same approval workflow.

`/join/{group:slug}/apply` renders the group's configurable joining
requirements: enabled personal fields, next of kin, identity media and
documents. When a face video is required, applicants can record it in the
browser with a phone front camera or laptop webcam; on-screen prompts guide
them to face front, turn left and right, tilt up and down, and finish facing the
camera while saying their full name and the date. Uploading a video file
remains available as a fallback, and phones require HTTPS for camera access.
Submissions are stored in the EDMS under
`edms/groups/{group_id}/members/{member_no}` as `pending` / `staff` files with
access logs. A new applicant becomes a `Member` with `status=applied`,
`kyc_status=pending` and metadata `application_source=public website`, and a
password setup link is sent when needed. Applicants cannot enter any tenant
page until approval. GET routes are throttled to 30/minute and POST to
10/minute.

Super admins review applications at **Platform → Applications**
(`/platform/applications`) in platform-managed groups. Approving sets the
member `active` and KYC `verified`, opens the default savings accounts and
wallet, records `member.approved` and sends the `member_approved` notification.
Required joining documents must be present or the approval is rejected unless
the reviewer explicitly checks **Override missing documents**; that override is
recorded as `member.approved.without_documents`. Rejecting requires a reason.
Ordinary members are redirected from `/dashboard` to `/portal` after approval.

## Requirements

- PHP 8.3+ with `bcmath`, `json`, `pdo`, `zip`
- Composer 2
- MySQL 8 (or MariaDB 10.6+) for local/production
- A queue worker and scheduler process

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the database named in `.env` (`chama_core` by default), then run:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

The seeder always creates the platform administrator from
`PLATFORM_ADMIN_EMAIL` / `PLATFORM_ADMIN_PASSWORD`. When `CHAMA_SEED_DEMO=true`
it also creates an active demonstration tenant:

| Item | Value |
|---|---|
| Group | Umoja Savings Group |
| Group administrator | `admin@umojademo.test` / `Password!2026` |
| Members | `member2@umojademo.test` ... `member12@umojademo.test` / `Password!2026` |

Never enable `CHAMA_SEED_DEMO` in production and change the platform password
immediately after the first login.

## Scheduled maintenance

`routes/console.php` schedules the following idempotent commands:

| Command | Schedule | Purpose |
|---|---|---|
| `chama:accrue-penalties` | daily 01:10 | Accrues overdue loan penalties |
| `chama:reconcile-ledgers` | daily 01:40 | Checks journals and control accounts |
| `chama:renew-subscriptions` | daily 02:10 | Renews auto-renew plans and expires others |
| `chama:send-reminders` | daily 08:00 | Sends admin-configured MGR, loan, bill, investment and custom date reminders |

Run them manually with, for example:

```bash
php artisan chama:accrue-penalties --date=2026-11-04
php artisan chama:reconcile-ledgers --strict
php artisan chama:send-reminders --date=2026-11-20
```

All commands accept `--group=<id>` for a single tenant. The scheduler needs this
cron entry on the server:

```cron
* * * * * cd /path/to/chama-core && php artisan schedule:run >> /dev/null 2>&1
```

Queue notifications and report exports with a supervised worker:

```bash
php artisan queue:work --tries=3 --timeout=120
```

## API

The REST API is versioned under `/api/v1` and authenticated with Sanctum bearer
tokens. The machine-readable contract is in
[`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1) and can be opened in
Swagger UI, Postman or Insomnia. Tenant endpoints require the `X-Group-Id`
header in addition to the token. Request amounts are decimal KES; monetary
responses are integer minor units.

### Bills

Group staff create bills from **Money → Bills**. A bill can be attached to a member
account (the member sees and pays it in the portal) or recorded against an external
customer/payee. Payments can be recorded at the counter with cash or bank, paid from
a member wallet, or requested through M-Pesa STK push. Bill payments settle the
`2040 Customer bill collections payable` ledger account.

The member API exposes `GET /api/v1/bills`, `GET /api/v1/bills/{bill}` and
`POST /api/v1/bills/{bill}/pay`; the latter accepts `method: wallet|mpesa`.
Each `BillPayment` records `settlement_amount`, the net cash actually available
to send to the biller (the amount less any fee deducted from the recipient).

### Bank transfers and payouts

Group staff open **Money → Bank transfers** to add destinations (bank account,
PayBill, Buy Goods till or M-Pesa phone number) and send money. The same screen
also controls member access:

- the group-wide switch can close bank transfers to members entirely;
- each member must then be individually opted in by an administrator.

Members with access see **Bank transfers** in the portal and can only send from
their own wallet. Funds are reserved into `2050 Bank transfers and payouts
clearing` when a transfer is created. A provider callback settles the reservation
to the bank account and charge income, or releases it back to the source if the
transfer fails. Duplicate callbacks are ignored.

Supported transports:

- generic bank transfer API (`BANK_PAYOUT_ENDPOINT`, `BANK_PAYOUT_TOKEN`);
- M-Pesa Daraja B2B PayBill/BuyGoods and B2C phone payouts
  (`MPESA_B2B_*` credentials and callback URLs).

Callback endpoints are `POST /api/v1/payouts/callback/{bank|mpesa}` and accept an
optional `X-Callback-Secret` header.

API clients can use `GET/POST /api/v1/payouts/destinations`,
`GET /api/v1/payouts/quote`, `GET/POST /api/v1/payouts`,
`GET /api/v1/payouts/{payout}` and `GET/PUT /api/v1/payouts/access`.

### Buying M-Pesa float from a wallet

Members can open **Buy M-Pesa float** in the portal (or use the API below) to
buy float for an M-Pesa agent store from their wallet. The destination is
identified by an **agent number** and a **store number** — the M-Pesa phone
number is the agent's registered line, not the float account. Staff register
agents under **Money → M-Pesa agents**; members can either pick a registered
agent/store or enter the numbers manually on the purchase form. The amount and
any charges are reserved immediately into `2050 Bank transfers and payouts
clearing`.

Two fulfilment modes are supported:

- **Manual (default)** — the purchase stays `pending` and appears at
  **Money → Float purchases** for a treasurer/accountant to mark fulfilled or
  fail. No external API credentials are required.
- **HTTP provider** — set `FLOAT_DRIVER=http`, `FLOAT_ENDPOINT` and `FLOAT_TOKEN`.
  The purchase is posted to the provider immediately and its response status is
  stored. `FLOAT_CALLBACK_URL`/`FLOAT_CALLBACK_SECRET` enable signed webhooks at
  `POST /api/v1/float/callback`.

Completing a purchase settles clearing to the bank account and charge income.
Failing one reverses the reservation and credits the full amount back to the
member's wallet with a `float_purchase_refund` transaction. Every transition is
audit logged and notifies the member.

Group admins can hide the feature from **Settings → Financial settings → Allow
M-Pesa float purchases**. The feature is enabled by default for groups that have
not saved that setting.

API clients can use `GET /api/v1/float/packages`, `GET /api/v1/float/agents`,
`GET /api/v1/float/quote`, `GET/POST /api/v1/float/purchases` and the signed
callback above. A purchase accepts either `agent_id` (a registered agent) or
manual `agent_number`, `store_number`, `agent_name` and `phone` fields.

### Payment charges

Super admins set platform defaults at **Platform → Payment charges**. Group admins
see those defaults at **Settings → Payment charges** and can override each rule.
There are six independently toggleable categories:

- bill payments
- all M-Pesa payments (including M-Pesa payouts)
- wallet deposits
- bank transfers and payouts
- M-Pesa float purchases
- every payment

Each rule supports percentage, fixed, fixed + percentage, or a manual amount entered
per payment, plus min/max caps. The charge can be paid on top by the payer, deducted
from the recipient, or absorbed by the group as an expense. Enabled matching rules
are additive and every fee is posted as a balanced double-entry journal.

The existing teller-desk `TransactionCharge` engine remains separate; payment
charges apply to M-Pesa intents, bill payments, internal wallet transfers,
bank transfers/payouts and M-Pesa float purchases.

### Currencies

Super admins enable or disable currencies at **Platform → Currencies**. The
catalogue in `config/chama.php` contains ISO codes from multiple countries;
only codes with two decimal places are offered because every amount is stored
as integer minor units (100 per major unit). Group admins choose from the
enabled codes at **Settings → Group profile → Currency**, so an organisation in
another country can use its own currency. Disabling a code removes it from new
selections but leaves groups already using it untouched until they switch.

### Member MGR payments and reminders

Members open **Merry-go-round** in the portal to see only the cycles they join.
A contribution can be paid from the member's wallet or requested through M-Pesa
STK push. The tendered amount includes any accrued late penalty, and payment
charges are quoted on that total. Every callback is idempotent and ownership is
enforced by `MemberScope` plus an explicit controller check.

Group administrators configure date reminders at **Notifications → Reminder
rules**. Rules cover MGR contributions, MGR payouts, loan repayments, bills,
investment maturity and custom dates. A rule can target the people involved in
the event, all active members or a selected list, and can run before/on/after
the date (`offset_days`, for example `-3`, `0`, `2`). Platform rules are
defaults; a group rule of the same type replaces it, including when disabled.
`chama:send-reminders` resolves the rules, and `reminder_deliveries` prevents a
duplicate notification being sent twice for the same date.

### Branches

A group administrator can create isolated branches under **Groups → Branches**.
A branch is a self-referencing `Group` row with its own members, wallets, loans
and ledger, but the head office remains its billing owner and branches cannot
change subscriptions. Settings, payment charges, reminder rules and platform
management mode are inherited from the nearest configured ancestor
(branch → parent branch → head office). A suspended branch cannot be selected
or entered.

### Platform management and revenue

On **Platform → Groups**, a super admin sets each organisation's management
mode:

| Mode | Effect |
|---|---|
| `self_managed` | Platform off; the group runs entirely on its own. |
| `oversight` | Platform can view the group and manage its charges/subscriptions. |
| `full` | Platform admins can also choose **Open tenant** and operate inside it. |

Marked groups keep operating normally in both managed modes. Full control uses
an explicit session key and a real, audited `group_admin` membership so the
existing permission checks and audit trail still apply. A banner in the tenant
layout lets the admin return to the platform console.

**Platform → Managed revenue** reports all posted income-account credits from
marked organisations alongside paid subscription invoices. Branches inherit
their parent's mode unless explicitly set to self-managed. This is a platform
report only: group books and gateway flows are not rewritten or transferred.

## Tenancy and privacy

Every tenant-owned table carries `group_id`. `GroupScope` filters queries by the
active group and `MemberScope` restricts ordinary members to their own rows.
Console jobs deliberately opt out with `withoutGlobalScopes()` or
`GroupContext::withoutTenantScope()`. Platform administrators normally manage
groups and subscriptions without tenant access. Only an organisation explicitly
set to **full** platform control can be opened, and only through the dedicated
**Open tenant** action; that action creates a real, audited `group_admin`
membership and is cancelled by **Return to platform**. Marked-group income is
reported on the platform revenue page without changing the group's own books.

## Deployment

Docker Compose is the recommended path for a VPS and ships with the repository:

```bash
cp .env.example .env
# edit APP_URL, APP_KEY, platform admin and CHAMA_DB_* values
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan optimize
```

The stack contains the application, Nginx, MySQL, Redis, a queue worker and the
scheduler. TLS terminates at a reverse proxy in front of Nginx. cPanel/shared
hosting and plain-VPS instructions, backups, updates, smoke tests and hardening
are in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Tests

```bash
php artisan test
```

The suite uses an in-memory SQLite database and covers loan mathematics, balanced
journals and reversals, tenant isolation, penalty idempotency, subscription
renewal and ledger reconciliation. SQLite is sufficient for these invariant
tests; production should still be exercised against MySQL.

## Project layout

```
app/Console/Commands      Scheduled financial maintenance
app/Http/Controllers      Web, portal, platform and API controllers
app/Models                Tenant-aware Eloquent models
app/Services              Domain services and double-entry posting
app/Services/Loans        Deterministic quotation and schedule engine
database/migrations       Shared-schema tenancy tables
database/seeders          Plans, roles, platform admin and demo tenant
docs/ARCHITECTURE.md      Domain and operations design
docs/CALCULATIONS.md      Interest, allocation and penalty formulas
docs/DEPLOYMENT.md        Docker, VPS and cPanel deployment guide
docs/openapi.yaml         OpenAPI 3.1 contract for the REST API
Dockerfile                PHP-FPM image for the application
docker-compose.yml        App, Nginx, MySQL, Redis, queue and scheduler
routes/web.php            Admin and member portal
routes/api.php            Sanctum API v1
routes/console.php        Scheduled commands
```

## Operational notes

- Payments are only recognised after a verified, idempotent gateway callback.
- Financial records are append-only; corrections use `LedgerService::reverse()`.
- Run `chama:reconcile-ledgers --strict` after deployments and investigate any
  reported difference before accepting further deposits.
- Back up the database, `storage/app` and the application key together.
