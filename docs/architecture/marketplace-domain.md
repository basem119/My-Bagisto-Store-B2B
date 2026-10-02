# Marketplace Domain Architecture (Phase 5)

Status: Foundational domain implemented. Checkout/fulfillment/commission/storefront are
documented here as boundaries only — not yet implemented (see "Deferred" at the end).

## 1. Company vs Vendor boundary

| | B2B Suite `Company` | Marketplace `Vendor` |
|---|---|---|
| Represents | Buyer organization | Seller organization |
| Backing table | `customers` row (`type = 'company'`) | New `marketplace_vendors` table |
| Membership | `b2b_customer_companies` pivot + `customers.company_role_id` | New `marketplace_vendor_users` pivot |
| Package | `vendor/bagisto/b2b-suite` (Composer dependency) | `packages/Webkul/Marketplace` (first-party, local) |

These are **structurally unrelated**. `Vendor` does not extend, implement, or reference
`Webkul\B2BSuite\Models\Customer` or any B2B Suite contract. A customer, a company, and a
vendor are three independent concepts that may all describe the *same person* without any
of them being a special case of another:

- A `customers` row is a storefront identity (login, cart, orders).
- A `customers` row *may additionally* be a B2B company (buyer org) via `type` + the B2B
  Suite tables.
- A `customers` row *may additionally* be a vendor team member via
  `marketplace_vendor_users` — entirely independent of whether that customer is also a B2B
  company member.

No inheritance, no shared table, no shared `type` enum. This directly satisfies the Phase 5
rule: "Do NOT reuse the B2B Suite Company table/model for Marketplace Vendor" and "Do NOT
create a Vendor → Company inheritance relationship."

## 2. Vendor ownership model

`marketplace_vendors` is a first-class table, independent of `customers` and `admins`:

- `id`, `name`, `slug` (storefront-ready, unique), `status`, `email`, `phone`
- `description`, `logo_path`, `banner_path` (storefront/profile foundation, Section 10)
- `meta_title`, `meta_description`, `meta_keywords` (SEO foundation, Section 10)
- `approved_at`, `suspended_at` (lifecycle timestamps)
- timestamps

Status is an explicit PHP enum (`Webkul\Marketplace\Enums\VendorStatus`), not a free string:
`pending`, `active`, `suspended`, `rejected`, `inactive`. Centralized in one place; every
status comparison in the codebase goes through this enum, never a raw string literal.

### Why not reuse `product_inventories.vendor_id`?

Investigated (carried over from Phase 1 findings): `product_inventories.vendor_id` is an
integer column, default `0`, unique per `(product_id, inventory_source_id)`. It identifies
which **inventory source** supplied a stock count for a product/warehouse row — it is a
stock-keeping concept, has no foreign key to any identity table, no name/profile/status, and
is never used for authorization or commerce ownership anywhere in Bagisto core. It is **not**
a marketplace seller reference and was never intended to be one. Reusing it would silently
conflate "which warehouse reported this stock number" with "who is commercially selling this
product" — two unrelated facts that must be allowed to diverge (e.g., a vendor's product
could be fulfilled from a shared warehouse with its own unrelated `inventory_source_id`).
**Decision: left untouched. A new, explicit `marketplace_vendor_products.vendor_id` column
is the real commercial-ownership relationship.**

## 3. Vendor membership model

New table `marketplace_vendor_users`:

- `id`, `vendor_id` (FK → `marketplace_vendors.id`, cascade delete)
- `customer_id` (FK → `customers.id`, cascade delete)
- `role` (enum: `owner`, `admin`, `manager`, `staff` — `Webkul\Marketplace\Enums\VendorUserRole`)
- `status` (`active`, `invited`, `suspended` — reuses the lightweight pattern, not the full
  Vendor status enum, since member lifecycle and vendor lifecycle are different concerns)
- unique `(vendor_id, customer_id)` — one membership row per person per vendor
- timestamps

This mirrors the proven B2B Suite `b2b_customer_companies` pivot shape deliberately (same
"customer participates in an organization via a side table" pattern), but is a **completely
separate table** — satisfying "do NOT assume a customer can belong to exactly one business
context." A single `customers` row can simultaneously have rows in `b2b_customer_companies`
(buyer side) and `marketplace_vendor_users` (seller side) with zero structural conflict,
because neither table modifies the `customers` row itself.

Identity/auth is intentionally **not** duplicated: vendor team members log in through the
existing `customer` guard (same login, password reset, session system Bagisto already has).
No new auth guard, no new credentials table. This keeps Phase 5 focused on the domain model,
not a parallel authentication system.

## 4. Vendor role / permission model

Four roles, stored directly on the membership row (not a separate roles table, unlike B2B
Suite's custom per-company `CompanyRole` table): `owner`, `admin`, `manager`, `staff`. This
is deliberately simpler than B2B Suite's custom-permission JSON column, because Phase 5 only
needs to answer *"can this customer act on this vendor, and at what level"* — a fixed,
small role set is sufficient and avoids speculative complexity. If per-vendor custom
permissions become a real requirement later, that can be layered on top of this column
without a breaking migration (e.g., an additive `permissions` JSON column).

Bagisto's Admin **Bouncer**/ACL middleware (`Webkul\User\Http\Middleware\Bouncer`) was
inspected and deliberately **not reused** — it is wired to the `admin` guard and Bagisto's
admin-role ACL tree, which is a platform-staff concept, not a per-vendor scoping concept.
Reusing it would require either (a) making every vendor an `admins` row (wrong identity
model — vendors are customers, not platform staff), or (b) shoehorning vendor-scoping into a
system designed for global admin permissions. Instead, vendor authorization uses a Laravel
**Policy** (`Webkul\Marketplace\Policies\VendorPolicy`), which is the standard, lightweight,
already-idiomatic-to-Laravel mechanism for "does this specific actor have permission to act
on this specific resource instance."

**Vendor-scoped rule, enforced in the policy, not scattered through controllers:**
a membership row's existence (and role) for `(customer_id, vendor_id)` is the *only* source
of truth. There is no global "is this customer a vendor admin" check — every authorization
decision is parameterized by a specific `Vendor` instance, so a Vendor A admin is
structurally incapable of resolving `true` for Vendor B (no matching row exists).

## 5. Vendor product ownership — Product vs Vendor Offer

```
Webkul\Product\Models\Product   (unchanged, stock Bagisto)
        ▲
        │ product_id (FK)
        │
Webkul\Marketplace\Models\VendorProduct   ("vendor offer")
        │
        │ vendor_id (FK)
        ▼
Webkul\Marketplace\Models\Vendor
```

`marketplace_vendor_products` ("vendor offer"):

- `id`, `vendor_id` (FK → `marketplace_vendors.id`, cascade), `product_id` (FK →
  `products.id`, cascade)
- `vendor_sku` (nullable — vendor's own SKU, independent of the product's canonical SKU)
- `price` (vendor's selling price for this offer — decimal, see Section 6)
- `quantity` (vendor-owned stock count for this offer)
- `status` (`VendorProductStatus`: `draft`, `active`, `inactive`)
- unique `(vendor_id, product_id)` — one offer per vendor per product (a vendor cannot
  double-list the same product; **multiple vendors** can each have their own offer row
  against the *same* `product_id`, which is exactly the multi-vendor "Product A → Vendor A
  offer / Vendor B offer" requirement)
- timestamps

No data is duplicated from `Product` (no copied name/attributes/images) — `VendorProduct`
only stores the facts that are genuinely vendor-owned (price, quantity, vendor SKU, offer
status). Everything else (name, attributes, media, SEO) continues to live on the single
canonical `Product` row and is looked up through the existing `product_id` relationship.

## 6. Vendor pricing vs B2B customer-group/company pricing

These remain **completely separate data paths**, never cross-written:

```
Vendor's selling price for an offer
        → marketplace_vendor_products.price  (NEW, marketplace-owned)

Buyer's negotiated/customer-group/company-catalog price
        → product_customer_group_prices       (EXISTING, B2B Suite-owned, untouched)
```

`VendorProduct::price` is never read or written by any B2B Suite code path, and no B2B Suite
table is touched by the Marketplace package. If a future phase needs "what does customer X
pay for vendor Y's offer of product Z" (combining both), that composition belongs in a new,
explicit service in a later phase (e.g., a `MarketplacePricingResolver`) — **not** by
modifying either existing table. No such service exists yet; documented here only as the
future integration seam.

## 7. Buyer Company integration boundary

The Marketplace package does not import, extend, or depend on any
`Webkul\B2BSuite\*` class. The only possible future touch points (not built yet) would be a
*composition* service that reads from both `Webkul\B2BSuite\Models\Customer`'s company
relationship and `Webkul\Marketplace\Models\VendorProduct` to build a combined storefront
listing — strictly additive, read-only composition, never a modification of B2B Suite
internals.

## 8. Marketplace order architecture (boundary only — not implemented this phase)

Planned future shape (schema/code **not** created in Phase 5):

```
Cart (existing Webkul\Checkout\Models\Cart)
   ↓ checkout
Webkul\Sales\Models\Order (existing, unmodified)
   ↓ (new, future)
marketplace_orders            — 1:1 wrapper row referencing orders.id
   ├── marketplace_vendor_orders   — one per vendor represented in the order
   │        └── references order_items belonging to that vendor's offers
   └── ...
```

The existing `orders`/`order_items`/`shipments`/`invoices` tables are not modified. A future
phase would add a thin "vendor order" layer that groups existing `order_items` by the vendor
that owns the underlying `VendorProduct`, rather than duplicating order data.

## 9. Commission / settlement boundary (not implemented this phase)

Planned future shape only:

```
Vendor Order → Gross Amount → Commission (rate/table TBD) → Vendor Net Amount → Settlement
```

Deliberately has zero models/migrations in Phase 5 — it depends on the (also deferred)
vendor order layer above, and mixing it in now would be exactly the premature-abstraction
the task guidance warns against.

## 10. Vendor storefront boundary

Already covered by `marketplace_vendors` columns added in Section 2
(`slug`, `name`, `description`, `logo_path`, `banner_path`, `meta_*`). No frontend route or
view exists yet — the backend data model is simply ready for one.

## 11. Admin vs Vendor portal boundary

No HTTP routes/controllers are added in Phase 5 (explicitly deferred). The domain is built
so that when those are added:

- **Platform Admin** actions (approve/suspend/reject a vendor) go through
  `VendorService::approve()/suspend()/reject()` — callable from an admin-guarded controller.
- **Vendor Portal** actions (manage own staff/offers) go through `VendorPolicy` +
  `VendorMembershipService`/`VendorProductService` — callable from a customer-guarded
  controller, always scoped to the acting customer's own vendor membership.

The same services are reusable from both contexts; the boundary is enforced by *who is
allowed to call which service method*, not by duplicating logic per portal.

## 12. GraphQL boundary

`bagisto/graphql-api` v2.3.2 was inspected; B2B Suite has zero GraphQL integration (confirmed
in earlier phases), and the Marketplace package follows the same stance: **no GraphQL
resolvers, types, or schema files are added in Phase 5.** Domain services
(`VendorService`, `VendorMembershipService`, `VendorProductService`) are plain,
framework-agnostic classes with no GraphQL/Lighthouse dependency, so a future GraphQL layer
can call them directly without any business logic living in a resolver.

## 13. Package structure

```
packages/Webkul/Marketplace/
    composer.json
    src/
        Enums/            VendorStatus, VendorUserRole, VendorUserStatus, VendorProductStatus
        Models/            Vendor, VendorUser, VendorProduct, VendorStatusHistory
        Repositories/      VendorRepository, VendorUserRepository, VendorProductRepository
        Services/          VendorService, VendorMembershipService, VendorProductService
        Events/            VendorCreated, VendorApproved, VendorSuspended, VendorRejected,
                           VendorMemberAdded, VendorMemberRemoved,
                           VendorProductAttached, VendorProductDetached
        Policies/          VendorPolicy
        Providers/         ModuleServiceProvider (Concord), MarketplaceServiceProvider
                           (policy/event registration)
        Database/Migrations/
```

This mirrors the existing `packages/Webkul/<Name>/src/...` convention used by every other
package in this repository (including the custom `Paymob` package), registered the same way
— a `path` Composer repository + a `ModuleServiceProvider` listed in `config/concord.php`'s
`modules` array. No new structural convention was invented.

## 14. Rejected alternatives

- **Vendor = Company subtype** — rejected outright per explicit instruction; also wrong
  domain modeling (buyer organization ≠ seller organization).
- **Vendor staff = `admins` row** — rejected; vendors are not platform staff, and reusing the
  `admin` guard/Bouncer ACL would conflate "manages the platform" with "manages their own
  shop."
- **Vendor staff = new `type` value on `customers`** — rejected; would recreate the exact
  "customer can only be one type" conflation the task explicitly warns against (and which
  B2B Suite itself already exhibits via its single `type` enum).
- **Reusing `product_inventories.vendor_id`** — rejected; see Section 2.
- **Full custom-permission JSON per vendor role (mirroring `CompanyRole`)** — deferred; a
  fixed 4-role enum is sufficient for this phase and is additively upgradable later.

## Deferred (explicitly NOT built in Phase 5)

Vendor portal (HTTP/UI), vendor storefront (frontend), catalog management UI, vendor pricing
UI, multi-vendor cart, parent/vendor orders, fulfillment, RFQ marketplace integration,
independent PO (if ever required), commissions, settlements, notifications, GraphQL
marketplace API, final security/regression/performance/payment testing.
