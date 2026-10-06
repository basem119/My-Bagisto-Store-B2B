# Marketplace Financial Ledger (Phase 12A)

Phase 12A adds only the reusable posting foundation. It does not implement order
financialization, fees, commissions, payments, credit integration, refunds, settlements,
cancellations, or vendor platform fees.

## Records

`marketplace_financial_transactions` is an immutable event/posting header. It records an
event type, a source discriminator and source ID, an EGP currency, a globally unique
idempotency key, and optional concrete references to existing Orders, OrderItems, Vendors,
Companies, and admins.

`marketplace_financial_entries` contains the immutable debit/credit lines for that header.
Each entry has one fixed account code, an EGP amount, and optional concrete Vendor, Company,
Order, and OrderItem references. Both tables restrict deletion of referenced business
records; deleting a creator admin sets only `created_by` to null.

## Posting rules

`FinancialTransactionService::post()` is the supported write boundary. It validates EGP,
source/event identifiers, account codes, amount shape, one-sided entries, and equal totals
before writing. A database transaction persists the header and every entry atomically. A
globally unique idempotency key makes concurrent retries race-safe: an identical payload
returns the prior transaction, while a conflicting payload raises
`IdempotencyConflictException`.

Entries must have exactly one positive side. Account balance directions are fixed by
convention: receivables and cash are debit-normal; vendor payable, platform revenue, and
delivery revenue are credit-normal. Corrections are new reversing transactions, never edits
or deletes.

## Currency and precision

Only EGP is accepted. Both amount columns use `DECIMAL(18,4)`, matching the B2B credit
ledger's monetary capacity and keeping the two Marketplace ledger tables consistent. EGP
values are rounded half-up to two minor digits at the posting boundary, matching Bagisto's
collected-total rounding. Amount arithmetic and balance comparisons use integer minor units;
binary floats are rejected. Persisted amounts therefore have four stored decimal places,
with the final two places zeroed.

## Concurrency convention

Phase 12A does not maintain balances. Later operations that test or mutate a balance/exposure
must lock its owning row with `lockForUpdate()` inside the same database transaction as the
ledger posting. The idempotency unique constraint remains the final guard against duplicate
event posting.

## Fixed account codes

The account code enum currently defines `company_receivable`, `vendor_payable`,
`platform_revenue`, `delivery_revenue`, and `platform_cash`. No configurable account table or
account rows are created.

## Future integration points

Later phases may post entries from Order/OrderItem financial snapshots, payment allocations,
refunds, settlements, and vendor fee obligations. Those workflows and their tables are not
implemented here. Company resolution and B2B credit integration remain Phase 12B+ work.