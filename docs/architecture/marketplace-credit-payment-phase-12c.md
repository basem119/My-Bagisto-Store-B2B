# Marketplace Credit and Payment Plans (Phase 12C)

Phase 12C adds company credit enforcement and payment-plan records around Marketplace orders. It reuses B2B Suite's existing company-credit tables and `CreditManager`; it creates no Marketplace credit balance or credit-ledger tables. These workflows do not post to the Phase 12A Marketplace financial ledger.

## Credit order boundary

`MarketplaceCompanyResolver` remains the only customer-to-company resolver. The `CreateMarketplacePaymentPlan` listener handles `paybycredit` orders on Bagisto's `checkout.order.save.after` event. The event is dispatched inside `OrderRepository::createOrderIfNotThenRetry()`'s database transaction. Runtime listener ordering places Marketplace financialization and plan creation before B2B Suite's `Order::afterCreated()` listener, which is the existing owner of `CreditManager::purchase()`.

Before the B2B listener charges the order, `MarketplaceCreditValidationService` locks the existing `b2b_company_credits` row through B2B Suite's `CompanyCreditRepository::findForUpdate()`. It compares integer EGP minor units for outstanding balance plus the order amount against the credit limit. Marketplace does not honor `allow_exceed_limit`; inactive/missing/non-EGP facilities and projected exposure above the limit reject the order. The lock remains held through the surrounding order transaction, so concurrent Marketplace orders for a company serialize. Cart-total collection and read-only checkout views do not mutate credit.

Both the overall B2B Suite activation flag and its credit-specific activation flag must be enabled. The overall flag is required because B2B Suite's own order listener skips its purchase when that module is disabled.

The B2B Suite order listener performs the sole order purchase mutation and appends its existing B2B credit transaction. If plan creation or later order work fails, Bagisto rolls back the order, plan, B2B balance change, and B2B transaction together.

## Company policies and plan snapshots

`marketplace_company_payment_policies` stores at most one policy per company, enforced by a unique company key. It controls maximum term days, whether installments are allowed, the maximum installment count, and active state.

`marketplace_payment_plans` stores one plan per order and snapshots the company, EGP total, agreed term, due date, and plan status. Plan creation validates the then-current policy and credit exposure. The database's unique `order_id` and the service's locked idempotency check prevent duplicate plans. A retry returns the original plan without revalidating it against a subsequently changed policy; explicitly conflicting retry parameters are rejected.

`marketplace_payment_installments` stores numbered, dated EGP obligations. `(payment_plan_id, installment_number)` is unique, amounts are split in minor units with any remainder assigned to the last installment, and paid amounts cannot exceed the installment amount. `markOverdueInstallments()` is available to a future scheduler; no scheduled command is registered in this phase.

## Manual payments and allocations

`marketplace_payments` stores manually recorded EGP payments. Recording locks the existing active B2B credit row, rejects a payment greater than the current outstanding balance, persists the payment, and invokes B2B Suite's `CreditManager::reimburse()` within the same transaction. This prevents negative outstanding balances and advance-credit behavior. The B2B credit transaction remains the authoritative credit history.

`marketplace_payment_allocations` links recorded payments to installments. Allocation locks both rows, requires the payment and plan to belong to the same company, and rejects amounts above either the payment's unallocated balance or the installment's remaining due amount. Multiple partial allocations may target the same installment. Allocation updates only Marketplace payment/installment bookkeeping and never reimburses B2B credit a second time. Fully paid installments complete their plan.

## Precision and exclusions

All Marketplace persisted monetary columns use `DECIMAL(18,4)` and EGP checks. Calculations use `EgpAmount` integer minor units; conversion to `float` occurs only at the B2B Suite `CreditManager` API boundary, whose public methods require `float` and perform their own existing float-based mutation.

This phase does not add customer wallet/advance credit, payment-provider changes, refunds, cancellation flows, vendor settlements or withdrawals, guarantee exposure, order splitting, new order statuses, Marketplace ledger postings, GraphQL changes, or B2C behavior. Bagisto core and B2B Suite source files remain untouched.
