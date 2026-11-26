# Chama Core — Financial Calculation Rules

This document is normative. The loan, savings, wallet and accounting engines must
implement these rules exactly, and automated tests must cover them.

## 1. Money and rates

- All monetary values are stored as **signed 64-bit integers in minor units**
  (Kenyan cents). `100000` means KES 1,000.00. Floating point is never used for
  stored balances.
- Calculations use `bcmath` decimal arithmetic at scale 8 and are rounded
  half-up to whole cents only when an amount is persisted.
- Interest rates are stored as **basis points per interest period** (`1000` =
  10% per period). Multipliers are stored in basis points (`30000` = 3.0x).
- Every rate row carries its own `interest_period`:
  `weekly`, `monthly`, or `annual`.
- Every loan product carries a `day_count_convention`:
  `actual_365`, `actual_366`, or `thirty_360`.

## 2. Period lengths

| Convention | Days in year | Days in month | Days in week |
|---|---:|---:|---:|
| actual_365 | 365 | 365/12 = 30.41666667 | 7 |
| actual_366 | 366 | 366/12 = 30.5 | 7 |
| thirty_360 | 360 | 30 | 7 |

Quoted-period length:

- weekly = 7 days
- monthly = days-in-year / 12
- annual = days-in-year

## 3. Converting a quoted rate to a repayment period

Repayment periods are weekly or monthly. A product may quote interest weekly,
monthly or annually **independently** of repayment frequency.

Two accrual modes are supported:

### 3.1 Effective (default, compounding-equivalent)

```
daily_rate      = (1 + quoted_rate) ^ (1 / quoted_period_days) - 1
period_rate     = (1 + daily_rate) ^ actual_days_in_repayment_period - 1
```

### 3.2 Simple (configured per product)

```
period_rate     = quoted_rate * actual_days_in_repayment_period / quoted_period_days
```

The same formulas convert weekly interest to monthly repayments and vice versa.
The conversion is performed once per installment, using the actual number of
days between the previous due date and the current due date (first installment:
disbursement date to first due date). This is the **single source of interest
accrual**; interest is never charged twice for the same days.

Rounding: `period_rate` is kept at 8 decimal places for calculation. Interest is
rounded to cents only when an installment line is written.

## 4. Flat-rate loans

```
quoted_periods   = term_days / quoted_period_days
total_interest   = principal * quoted_rate * quoted_periods
total_repayable  = principal + total_interest
installment      = total_repayable / number_of_repayments
```

Each schedule line has equal principal and equal interest components. The final
line absorbs rounding so the sum of lines equals `total_repayable` exactly.
Early settlement rebate is an explicit, approved adjustment; it is never
computed silently.

## 5. Fixed-interest loans

The administrator supplies a fixed interest amount (or a fixed total). The
interest is spread evenly across installments. The schedule, allocation and
accounting behaviour are otherwise identical to flat-rate loans.

## 6. Reducing-balance loans

Interest is calculated on the outstanding principal for each repayment period
using `period_rate` from section 3.

### 6.1 Equal-installment (annuity)

```
i = period_rate for the first period (converted, section 3)
n = number of installments
payment = principal * i / (1 - (1 + i)^-n)
```

For every installment: `interest = outstanding_principal * i_period`, where
`i_period` is recalculated for that period's actual days. `principal_component =
payment - interest`. If a rate recalculation changes the payment, the payment is
recalculated for the remaining term; this is recorded on the schedule. The final
installment is adjusted so the principal sums exactly to the loan principal.

For products with a constant period length the standard annuity result is stable;
for varying calendar lengths (monthly due dates) the recalculation rule above
prevents drift.

### 6.2 Equal-principal

```
principal_component = principal / n
interest            = outstanding_principal * i_period
installment         = principal_component + interest
```

### 6.3 Rate change / restructuring

A restructure closes the old schedule at its paid-to date, records the unpaid
principal, and generates a new schedule from the restructure date. No interest is
charged on the same principal for overlapping days. The original agreement and
all schedules remain queryable.

## 7. Term

- Weekly repayment: due dates are every 7 days from the first due date.
- Monthly repayment: due dates use calendar months (`addMonthsNoOverflow`), with
  the day of month fixed to the disbursement day or the product's configured
  repayment day.
- Duration is configured in `duration_unit` (`days`, `weeks`, `months`) and must
  lie between the product's minimum and maximum.
- `term_days` is the actual number of days from disbursement to the final due
  date.

## 8. Payment allocation

Products define an ordered allocation policy. Supported buckets:

1. `penalties`
2. `fees`
3. `interest`
4. `principal`

Default policy: `penalties, fees, interest, principal`.

- A payment is applied in order until exhausted.
- Only **contractually due** penalties/fees may be collected ahead of interest.
- Overpayment is credited to the member wallet; it is never silently retained.
- Partial payments leave the unpaid balance on the earliest open installment.
- Early full settlement pays all accrued interest to the settlement date, then
  principal, then waives future unearned interest only if an approved waiver
  exists.

## 9. Penalties

- Penalty rate and grace days are product settings.
- Penalty is charged on the overdue installment amount (not on the whole loan)
  once per penalty period (daily / weekly / monthly).
- Penalties are persisted as `penalty_charges` rows with a unique key
  `(installment_id, period_start)` so re-running the scheduler is idempotent.

## 10. Loan multiplier engine

```
eligible_savings = sum(savings balances where product qualifies and not pledged)
eligible_shares  = sum(share balances where product qualifies) * share_weight
base             = eligible_savings + eligible_shares
gross_limit      = base * product_multiplier
exposure         = outstanding principal of active loans
                   + active guarantee commitments
                   + product-specific deductions
available        = max(0, gross_limit - exposure)
final_eligible   = min(available, product.max_amount, group.available_lending_funds,
                       requested_amount if supplied)
```

Eligibility also requires: active membership, minimum membership duration,
no loans in arrears, KYC approved, and guarantor capacity when guarantees are
required. Pledged or restricted savings are excluded. Every calculation returns
a line-by-line breakdown that is shown to the borrower and stored with the
application.

## 11. Guarantor capacity

```
capacity = guarantor_eligible_base * guarantee_multiplier
           - active_guarantee_commitments
```

A guarantee request can only be accepted when `capacity >= requested_guarantee`.
Accepting a request locks the commitment; settlement or release frees it. A
member cannot accept on another member's behalf; the API verifies that the
authenticated user is the guarantor.

## 12. Wallet and ledger

- Every wallet movement writes a wallet transaction **and** a balanced journal
  entry in the same database transaction.
- External payments carry a unique `idempotency_key`; duplicate callbacks return
  the original result.
- Wallet balances are derived from ledger-backed wallet transactions; a
  reconciliation command asserts `wallet.balance == sum(transactions)` and
  `sum(debits) == sum(credits)` per journal entry.
- Member savings, shares and loan balances are liabilities/assets of the group,
  never of the platform. Group ledgers are fully isolated by `group_id`.

## 13. Merry-go-round

- Each cycle has ordered payout positions and a fixed contribution amount.
- A contribution is due on its schedule date; penalties use the group's fine
  policy and are idempotent.
- One payout per position per cycle. A payout is only released when the
  recipient has met the cycle's required contributions (configurable).
- Payout schedule dates are generated at cycle creation; approved position
  changes regenerate future schedules and are audited.

### 13.1 Automatic savings transfer

- The transfer is **disabled by default**. A group admin stores one
  `mgr_savings` setting with an enabled flag, a mode, a basis, a fixed amount in
  minor units and a percentage in basis points.
- The rule applies to **every** merry-go-round contribution, whether settled in
  the member portal (wallet or M-Pesa) or posted by staff/teller. It never
  applies to savings, loans, investments or other contribution types.
- **Split mode**: the member pays the quoted contribution. The configured
  portion moves to individual savings and the MGR pool receives the remainder.
  Example: `50_000` (KES 500) with a `10_000` (KES 100) portion produces
  `40_000` to MGR and `10_000` to savings.
- **Add-on mode**: the member pays the contribution **plus** the configured
  portion. The MGR pool receives the full contribution and savings receives the
  extra portion.
- A fixed portion is the stored minor-unit amount. A percentage portion is
  `round(contribution * rate_bps / 10000)`, with `10000 bps = 100%`.
- The savings credit reuses `SavingsService::deposit()` with `MGR_FUNDS` as the
  counter account, so the journal debits `MGR_FUNDS` and credits
  `SAVINGS_LIABILITY`. The first active savings product is used (mandatory
  products first); a new account is opened when required.
- Posting is idempotent per contribution using the key
  `mgr-auto-savings-{contribution_id}`. Payout amounts are recalculated at cycle
  creation and update from the effective MGR pool after the split.

## 14. Rounding and reconciliation invariants

1. Sum of installment principal = loan principal.
2. Sum of installment interest = total interest charged for the term.
3. Sum of journal debits = sum of journal credits for every entry.
4. Wallet balance = sum of wallet transactions.
5. Loan outstanding principal = principal − principal allocated.
6. No financial mutation occurs outside a database transaction.
