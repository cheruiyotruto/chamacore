# Chama Core — Architecture

## 1. Runtime

- **Laravel** (latest stable) on PHP 8.4, MySQL 8, Redis-compatible cache/queue.
- **Blade + Alpine.js + Tailwind CSS** for the administration portal and the
  member self-service portal. Server-rendered pages keep financial data access
  on the backend; Alpine is used only for interaction.
- **Laravel Sanctum** bearer tokens for the REST API consumed by Flutter.
- **Flutter** app (Android/iOS) in `mobile/`, calling the same versioned API.
- Scheduled commands handle penalties, due-date reminders, subscription
  renewals, reconciliation and backups.

## 2. Multi-tenancy and isolation

Single database, shared schema, `group_id` on every tenant-owned table.

- `BelongsToGroup` trait adds a global scope on the authenticated user's active
  group and auto-fills `group_id` on create.
- A member's active group is resolved from the session (web) or the
  `X-Group-Id` header / token's group context (API). Users can belong to many
  groups; membership is the pivot with role and status.
- Super administrators are platform staff. They can manage groups and
  subscriptions but cannot read member financial records through normal
  tenant-scoped queries; any platform-level aggregate is anonymised.
- Policies and form requests verify group membership and member ownership on
  every read and write. A member may only query rows whose `member_id` is their
  own membership in that group.
- Tests assert that a member of group A cannot read group B rows and cannot read
  another member's rows, even by guessing IDs.

## 3. Domain modules

| Module | Core tables | Services |
|---|---|---|
| Identity & tenancy | users, groups, group_user, roles, permissions | GroupContext, Rbac |
| Members & KYC | members, member_documents, beneficiaries, next_of_kin | MemberOnboarding |
| Savings & shares | savings_products, savings_accounts, savings_transactions | SavingsService, LedgerService |
| Merry-go-round | mgr_cycles, mgr_participants, mgr_contributions, mgr_payouts | MerryGoRoundService |
| Table banking | meetings, attendance, meeting_collections, fines | MeetingService |
| Loans | loan_products, loans, loan_schedules, loan_payments, loan_approvals, guarantors, penalties | LoanCalculator, LoanOrigination, LoanServicing, MultiplierEngine |
| Wallet & payments | wallets, wallet_transactions, payment_intents | WalletService, MpesaGateway |
| Accounting | ledger_accounts, journal_entries, ledger_lines, bank_accounts, reconciliations | LedgerService, ReportsService |
| CRM | leads, enquiries, tickets, communications | CrmService |
| Governance | constitutions, policies, meetings, minutes, resolutions, votes | GovernanceService |
| Welfare & fines | welfare_funds, welfare_claims, fine_types | WelfareService |
| Documents | documents, document_versions | DocumentService |
| Notifications | notifications, notification_templates, notification_logs | NotificationService |
| SaaS | plans, subscriptions, subscription_invoices, payments | SubscriptionService |

## 4. Financial posting

Every financial mutation goes through a domain service wrapped in
`DB::transaction`. The service:

1. locks the affected rows (`lockForUpdate`);
2. writes the business record (e.g. `loan_payments`);
3. posts a balanced `journal_entries` + `ledger_lines` pair;
4. writes an audit event;
5. dispatches notifications only after commit.

`LedgerService::post()` validates that total debits equal total credits and that
every account belongs to the same group. Reversals create a mirrored journal
entry linked to the original; records are never deleted.

## 5. Authentication and authorisation

- Web: session auth, password hashing (argon2id), email verification, optional
  TOTP MFA, login throttling, session invalidation on password change.
- API: Sanctum personal access tokens with abilities; token creation requires
  password + optional MFA code.
- RBAC: roles are group-scoped (`group_admin`, `chairperson`, `treasurer`,
  `secretary`, `accountant`, `loan_officer`, `credit_committee`,
  `supervisory_committee`, `member`) plus platform `super_admin`.
- Permissions are granular (`loans.approve`, `savings.withdraw`,
  `reports.view`, ...). Officials get only the permissions configured for their
  group role. Approval limits are stored per role.
- Four-eyes rule: the actor who submitted/created a loan cannot approve it, and
  the same actor cannot satisfy two approval steps that require distinct users.

## 6. Loan lifecycle

```
draft -> submitted -> guarantors_pending -> under_review -> approved/rejected
      -> disbursed -> active -> (restructured) -> closed/defaulted
```

- Approval chain is data-driven (`approval_workflows`, `approval_steps`).
- Schedule is generated at approval time and stored; it is not recomputed on
  every page view.
- Payments, penalties and restructures are appended events; balances are updated
  in the same transaction and reconciled by invariant tests.
- The member dashboard shows the stored breakdown produced by the engine.

## 7. API

Versioned under `/api/v1`. JSON resources never expose other members' data.
Endpoints include auth, profile, groups, savings, contributions, MGR, loans,
repayment schedules, guarantor requests, wallet, statements, documents,
notifications and support tickets. OpenAPI documentation is generated in
`docs/openapi.yaml`.

Rate limits: 60 requests/minute per user, 5 login attempts/minute per IP,
payment callbacks verified by signature and idempotency key.

## 8. Payments and integrations

- `PaymentGateway` contract with `MpesaGateway`, `BankGateway` and
  `ManualGateway` implementations. Credentials live in group-level settings,
  encrypted at rest.
- Callbacks are signature-verified, idempotent, and reconciled before a wallet
  credit is recognised.
- SMS (Africa's Talking/compatible), email (SMTP/API), push (FCM) are queued
  jobs with retry and delivery logs. Templates are database-driven.
- No integration is faked in production: when credentials are absent the UI
  reports the integration as unconfigured instead of simulating a payment.

## 9. Operations

- Queue workers for notifications, statements and report exports.
- Nightly `chama:accrue-penalties`, `chama:send-reminders`,
  `chama:reconcile-ledgers`, `chama:renew-subscriptions` scheduled commands.
- Automated encrypted database backups and document storage backups.
- Health endpoint, structured logs, audit trail retention policy.
- Docker Compose and documented cPanel/VPS deployment are provided in
  [`DEPLOYMENT.md`](DEPLOYMENT.md).

## 10. Compliance guardrails

- The platform records group-managed savings and internal lending; it does not
  claim to be a licensed deposit-taking institution.
- Consent, data-subject access/export and erasure workflows support the Kenya
  Data Protection Act, 2019.
- Platform staff access to tenant financial data is blocked by policy and
  audited; support access requires an explicit, time-boxed grant by the group.
