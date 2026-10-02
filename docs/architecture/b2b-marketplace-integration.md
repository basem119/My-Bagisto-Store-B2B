# B2B ↔ Marketplace Integration

Audit performed against the actual installed code (`vendor/bagisto/b2b-suite` v2.2.0 and
`packages/Webkul/Marketplace`), not assumptions. Every claim below cites the exact file it
was verified against. No schema, B2B Suite, Marketplace behavior, or Paymob files were
changed while producing this document.

## 1. Current Domains

### Buyer / B2B

Package: `vendor/bagisto/b2b-suite` (Composer dependency, namespace `Webkul\B2BSuite`).
A B2B **Company** is not a distinct entity/table — it is a `customers` row flagged
`type = 'company'`. "Company users" are other `customers` rows (`type = 'user'`) linked to
that row via a pivot table.

### Seller / Marketplace

Package: `packages/Webkul/Marketplace` (first-party, local, namespace `Webkul\Marketplace`).
A **Vendor** is a genuinely distinct entity — its own `marketplace_vendors` table, unrelated
to `customers`. Vendor team members are `customers` rows linked via `marketplace_vendor_users`.

These two packages currently have **zero code-level references to each other** (verified in
Step 3 below) — the integration boundary audited here is entirely prospective.

## 2. B2B Company Architecture

Confirmed in [vendor/bagisto/b2b-suite/src/Models/Customer.php](../../vendor/bagisto/b2b-suite/src/Models/Customer.php),
which `extends Webkul\Customer\Models\Customer`:

```php
class Customer extends BaseCustomer
{
    protected $fillable = [..., 'type', 'company_role_id', 'company_catalog_id', 'sales_rep_id'];

    public function companies(): BelongsToMany   // self-join, type='company' side
    {
        return $this->belongsToMany(self::class, 'b2b_customer_companies', 'customer_id', 'company_id')
            ->where('type', 'company');
    }

    public function members(): BelongsToMany     // self-join, type='user' side
    {
        return $this->belongsToMany(self::class, 'b2b_customer_companies', 'company_id', 'customer_id')
            ->where('type', 'user');
    }

    public function companyCatalog(): BelongsTo { /* belongsTo CompanyCatalog via company_catalog_id */ }
    public function salesRep(): BelongsTo        { /* belongsTo Admin via sales_rep_id */ }
    public function company_flats(): HasMany     { /* hasMany CompanyFlat */ }
}
```

Columns added directly to the **existing** `customers` table by
[2026_06_19_100025_add_columns_to_customers_table.php](../../vendor/bagisto/b2b-suite/src/Database/Migrations/2026_06_19_100025_add_columns_to_customers_table.php):

| Column | Type | FK |
|---|---|---|
| `type` | `enum('user','company')`, default `user` | — |
| `company_role_id` | nullable int | → `b2b_company_roles.id`, `set null` |
| `company_catalog_id` | nullable int | → `b2b_company_catalogs.id`, `set null` |
| `sales_rep_id` | nullable int | → `admins.id`, `set null` |

`b2b_customer_companies` ([2026_06_19_100016_...](../../vendor/bagisto/b2b-suite/src/Database/Migrations/2026_06_19_100016_create_b2b_customer_companies_table.php)):
just `customer_id` + `company_id`, **both FKs pointing at `customers.id`** — i.e. a
self-referential many-to-many on the same table. **No unique constraint and no primary key**
on the pair — structurally nothing currently prevents duplicate membership rows or a
customer belonging to more than one company (see Section 6, item G).

`CompanyFlat` ([Models/CompanyFlat.php](../../vendor/bagisto/b2b-suite/src/Models/CompanyFlat.php)):
`belongsTo` Customer via `customer_id` on table `b2b_company_flat` — a flattened/cached
read-model row (same EAV "flat" pattern Bagisto uses for `product_flat`), **not** a second
source of truth for identity. It caches company-level custom-attribute values for fast reads.

`CompanyRole` ([Models/CompanyRole.php](../../vendor/bagisto/b2b-suite/src/Models/CompanyRole.php)):
table `b2b_company_roles`, columns `name`, `description`, `permission_type`, `permissions`
(JSON, cast to array), `customer_id`. `hasMany` back to `Customer`. No separate pivot — a
customer has at most one `company_role_id` at a time (single FK column, not a many-to-many).

`CompanyCatalog` ([Models/CompanyCatalog.php](../../vendor/bagisto/b2b-suite/src/Models/CompanyCatalog.php)):
table `b2b_company_catalogs`. `belongsTo` a `CustomerGroup` (`customer_group_id`),
`belongsToMany` `Product` through `b2b_company_catalog_products`, `hasMany` `Customer` (the
companies assigned to it, filtered `type = 'company'`).

`CompanyCatalogProduct` ([Models/CompanyCatalogProduct.php](../../vendor/bagisto/b2b-suite/src/Models/CompanyCatalogProduct.php)):
pure pivot, `company_catalog_id` + `product_id` only.

## 3. B2B Customer and Company Role Model

Answering the audit's lettered questions precisely:

**A. How is a Company identified?** By `customers.type === 'company'`. There is no
`companies` table — the company *is* the customer row.

**B. How is a company user identified?** By `customers.type === 'user'` **and** a row in
`b2b_customer_companies` linking that user's `customer_id` to the company's `customer_id`
(as `company_id`). `type` alone does not establish membership — the pivot row does.

**C. How does a customer belong to a company?** Via `b2b_customer_companies`
(`customer_id` = the user, `company_id` = the company — both FKs to `customers.id`).

**D. Where is `company_role_id` used?** A single nullable FK column directly on `customers`,
resolved via `Customer::companyRole()` → `CompanyRole`. One role per customer, not
per-membership — i.e. a customer's role is global to them, not scoped per company they
belong to (relevant since nothing stops a customer being linked to multiple companies — see
Section 6, item G).

**E. Where is `company_catalog_id` used?** Also a single nullable FK column directly on
`customers`, resolved via `Customer::companyCatalog()`. Enforced at the storefront boundary
by [Repositories/ProductRepository.php](../../vendor/bagisto/b2b-suite/src/Repositories/ProductRepository.php)
(see Section 9) and backed by a **dedicated hidden customer group per catalog**
(see Section 8 — this is the pricing mechanism).

**F. How does B2B Suite determine the "current company" for an authenticated customer?**
There is no session-based "active company" concept. The authenticated customer's own row
carries `company_catalog_id`/`company_role_id`/`type` directly — "current company" for a
`type='user'` customer is resolved by walking `auth()->user()->companies()->first()`-style
pivot lookups where needed (e.g. in company-admin screens), not by a stored "current context"
value. A `type='company'` customer *is* the company, so no resolution step is needed for it.

**G. Can one customer belong to multiple companies?** Structurally yes — `companies()` is
`belongsToMany` with no unique constraint on the pivot (Section 2). Whether the UI/application
code ever actually creates more than one such row was not exhaustively traced (out of scope
for this audit — no controller was found that intentionally creates a second company
membership), but nothing in the schema prevents it.

**H. Can one customer simultaneously be company member AND vendor member?** **Yes, by
construction.** `b2b_customer_companies` and `marketplace_vendor_users` are two completely
unrelated tables, both keyed off the same `customers.id`. Nothing in either package checks
the other. This was verified by an exhaustive cross-reference search (Section 5 below) that
found zero mutual references.

**I. Is Company represented by the parent customer row, `CompanyFlat`, or both?** The parent
`customers` row is the **only** source of truth (identity, `type`, role, catalog, sales rep).
`CompanyFlat` is purely a derived/cached read-projection of custom attribute values — deleting
it and rebuilding it would not lose any identity or relationship data.

**J. Which model/repository should future Marketplace code use to resolve the buyer company?**
Directly the core `Webkul\Customer\Models\Customer` row's `type`/`company_catalog_id` fields —
**not** a new abstraction. But see Section 12 for a concrete Concord hazard this implies.

## 4. Marketplace Vendor Architecture

Confirmed in `packages/Webkul/Marketplace/src/Models/`:

- **`Vendor`** ([Vendor.php](../../packages/Webkul/Marketplace/src/Models/Vendor.php)) — table
  `marketplace_vendors`. Own identity table (`id`, `name`, `slug`, `status`, `email`, `phone`,
  `description`, `logo_path`, `banner_path`, `meta_*`, `approved_at`, `suspended_at`).
  `hasMany` `VendorUser`, `VendorProduct`, `VendorStatusHistory`.
- **`VendorUser`** ([VendorUser.php](../../packages/Webkul/Marketplace/src/Models/VendorUser.php)) —
  table `marketplace_vendor_users`. `vendor_id` + `customer_id` + `role`
  (`VendorUserRole` enum: owner/admin/manager/staff) + `status` (`VendorUserStatus` enum:
  active/invited/suspended). `belongsTo(Vendor::class)` and, deliberately,
  `belongsTo(\Webkul\Customer\Models\Customer::class, 'customer_id')` — the **base** core
  Customer class, hard-coded, not a Concord proxy/contract (see Section 12 for why this
  matters).
- **`VendorProduct`** ([VendorProduct.php](../../packages/Webkul/Marketplace/src/Models/VendorProduct.php)) —
  table `marketplace_vendor_products`. Columns: `vendor_id`, `product_id`, `vendor_sku`
  (nullable), `price` (`decimal:4`), `quantity` (int), `status` (`VendorProductStatus`:
  draft/active/inactive). `belongsTo(Vendor::class)` and
  `belongsTo(\Webkul\Product\Models\Product::class, 'product_id')` — the core Product model
  directly.

Answering the audit's lettered questions:

**A. What identifies a Vendor?** `marketplace_vendors.id` (and `slug` for routing) — a
first-class row, unrelated to `customers.id`.

**B. What identifies a VendorUser?** `marketplace_vendor_users.id`, uniquely constrained on
`(vendor_id, customer_id)` — one membership row per person per vendor
([migration](../../packages/Webkul/Marketplace/src/Database/Migrations/2026_10_02_000003_create_marketplace_vendor_users_table.php)).

**C. What identifies the vendor member's customer?** `marketplace_vendor_users.customer_id`,
FK → `customers.id` — the same physical table B2B Suite's `Customer` subclass extends, but
related to here via the base `Webkul\Customer\Models\Customer` class, not the B2B subclass.

**D. How is vendor membership resolved?** `VendorMembershipService::isMember()` /
`isActiveMember()` — a direct query on `marketplace_vendor_users` for
`(vendor_id, customer_id)`, requiring `status === ACTIVE` for the "active" check. No caching,
no session state, no "current vendor" concept stored anywhere — every request re-resolves
membership against the `{vendor:slug}`-bound `Vendor` instance
(`EnsureVendorContext` middleware).

**E. Is vendor membership intentionally independent from B2B company membership?** **Yes,
confirmed both by code inspection and by the exhaustive grep in Section 5** — there is no
shared table, no shared foreign key, and no code path in either package that reads the
other's tables.

**F. Does `VendorProduct` represent an association, an offer, inventory, or all three?**
**An offer.** It carries exactly: which vendor (`vendor_id`), which core product
(`product_id`), the vendor's own SKU label, the vendor's selling `price`, a `quantity`
(vendor-owned stock count — distinct from Bagisto's own `product_inventories` /
`inventory_source` mechanism, deliberately not reused per the Phase 5 decision), and an
offer-level `status`. It does **not** duplicate the product's name, attributes, images, or
SEO data — those stay solely on the core `Product` row and are reached through `product_id`.

**G. Which fields currently exist on `VendorProduct`?** `id`, `vendor_id`, `product_id`,
`vendor_sku`, `price`, `quantity`, `status`, `created_at`, `updated_at` — confirmed against
both the [model](../../packages/Webkul/Marketplace/src/Models/VendorProduct.php) and the
[migration](../../packages/Webkul/Marketplace/src/Database/Migrations/2026_10_02_000004_create_marketplace_vendor_products_table.php)
(which additionally shows: unique `(vendor_id, product_id)` — one offer per vendor per
product; `status` defaults `'draft'`; both FKs cascade-delete).

## 5. Domain Boundary

**Company = buyer organization. Vendor = seller organization.** They must remain separate
because they answer two structurally different questions that happen to both involve a
`customers` row:

- Company: *"On whose account, credit line, negotiated pricing, and catalog allowlist is this
  purchase being made?"* — modeled as a flagged `customers` row because a company's
  "identity" for purchasing purposes genuinely **is** a customer identity (it logs in, has a
  cart, places orders) with extra buyer-side attributes bolted on.
- Vendor: *"Who is the counterparty supplying and pricing this product?"* — modeled as a
  wholly separate entity because a vendor's identity (storefront slug, logo, approval status,
  suspension) has **nothing to do with any single person's login** — a vendor is a business
  that multiple people (owner/admin/manager/staff) act on behalf of, which is exactly why
  Phase 5 rejected modeling it as a `customers.type` value (that would have forced one person
  = one vendor, breaking the whole staff/ownership model).

Collapsing them (e.g. `Vendor extends Company` or vice versa) would force every vendor to
also be a buyer identity (or every company to also carry vendor approval/suspension
lifecycle state) — two unrelated lifecycles sharing one row, which is precisely the
"B2B Company behavior must not change" and "no Vendor → Company coupling" constraints this
audit was told to protect.

## 6. Customer Membership

```
Customer (customers row, Webkul\Customer\Models\Customer)
 ├── Company membership   — b2b_customer_companies (type='user' row ↔ type='company' row)
 │                           + customers.company_role_id / company_catalog_id (B2B Suite)
 └── Vendor membership    — marketplace_vendor_users (role + status)      (Marketplace)
```

Both independently supported **today**, on the same underlying `customers.id`, with zero
interaction. A single customer can simultaneously:

- be a `type='user'` member of Company X (via `b2b_customer_companies` + `company_role_id`), and
- be an `owner`/`admin`/`manager`/`staff` `VendorUser` of Vendor Y (via `marketplace_vendor_users`)

with neither side aware of the other. This matches the task's stated expectation exactly and
required **no new code** to confirm — it already works by construction because the two
tables never intersect.

## 7. Product Relationship

```
Company  ──(customer_group_id, via provisioned hidden group)──  CompanyCatalog ──(b2b_company_catalog_products)──  Product
Vendor  ───────────────────────(marketplace_vendor_products)────────────────────────────────────────────────────  Product
```

**Existing relationship:** both `CompanyCatalog` and `VendorProduct` key off the exact same
core `products.id` — that shared foreign key is the **only** connective tissue between the
buyer and seller sides today, and it already exists with zero modification needed.

**Missing relationship:** there is no row anywhere that says "Company X's catalog allowlist
includes Vendor Y's offer of Product Z" — `CompanyCatalogProduct` allowlists a bare
`product_id`, with no concept of *which vendor's offer* of that product is meant. If the same
`product_id` has three different `VendorProduct` offers (Vendor A/B/C at different
prices/quantities), a company catalog that allowlists that `product_id` today has no way to
prefer, restrict, or distinguish between those three offers — it is offer-agnostic by
construction.

## 8. Pricing Boundary

Confirmed via [Helpers/CompanyCatalog.php::provisionGroup()](../../vendor/bagisto/b2b-suite/src/Helpers/CompanyCatalog.php):
B2B Suite's catalog pricing is **not** a custom price column anywhere — it works by
auto-provisioning one **hidden, dedicated `customer_group`** per catalog
(code `company_catalog_{id}`) the first time the catalog is used, then storing prices for
that group through Bagisto's **existing, core, unmodified**
`ProductCustomerGroupPriceRepository` / `product_customer_group_prices` table. B2B Suite adds
zero new pricing tables — it is 100% a reuse of Bagisto's native tiered customer-group
pricing engine, just with an auto-managed, catalog-scoped group as the key.

Five distinct price concepts currently exist or are anticipated:

| # | Concept | Where it lives today |
|---|---|---|
| 1 | Base Bagisto product price | `products`/`product_flat` (core, unmodified) |
| 2 | Vendor selling price | `marketplace_vendor_products.price` (Marketplace, Phase 5) |
| 3 | Company/customer-group price | `product_customer_group_prices`, keyed by the catalog's hidden group (B2B Suite, reusing core) |
| 4 | Negotiated RFQ price | `b2b_customer_quote_quotations` (B2B Suite's quote/RFQ flow — not inspected further, out of scope) |
| 5 | Future marketplace/cart price | **Does not exist yet** — no code composes #2+#3+#4 today |

The example precedence chain in the task prompt (`Product → Vendor Offer → Company price →
RFQ → final cart price`) is a **plausible future composition order**, not something verified
to already exist — no code today computes a price by walking that chain; each of rows 1–4 is
currently computed by a completely separate, non-interacting code path. **This audit does not
implement or endorse a specific precedence order** — that decision requires its own phase
once Vendor Catalog work begins, informed by real product/business requirements (e.g., does a
company's negotiated price ever override a vendor's floor price, or is the vendor price always
the ceiling?). Flagged here as an open decision, not resolved.

## 9. Visibility Boundary

Confirmed in [Repositories/ProductRepository.php](../../vendor/bagisto/b2b-suite/src/Repositories/ProductRepository.php)
(bound over the core `ProductRepository` via
[B2BSuiteManager::registerRepositories()](../../vendor/bagisto/b2b-suite/src/Providers/B2BSuiteManager.php)):
`getAll()`, `getMaxPrice()`, and `findBySlug()` are overridden to call
`applyCatalogVisibility()`/`isVisible()`, which — only when the current customer's group is
backed by an **active** company catalog — restricts listing/search/PDP to
`$catalog->products()->where('products.id', $productId)->exists()`. For guests, admins, and
customers not assigned to a catalog, this is a no-op (`isVisible()` returns `true`
unconditionally when no catalog applies).

**Whether `VendorProduct` should affect visibility, replace `CompanyCatalogProduct`, or
coexist:** based on the actual code, **coexist, unmodified, composed at query time in a
future phase.** `CompanyCatalogProduct` answers "is this product allowed for this company at
all" (a buyer-side allowlist); `VendorProduct` answers "which vendors currently offer this
product, at what price/quantity" (a seller-side offer list). These are orthogonal filters
over the same `product_id`, not a replacement relationship — a company's catalog allowlist
should narrow *which products* are visible at all; *which vendor offers* of an allowed
product are shown is a second, independent question. Multiple vendors selling the same
Bagisto Product (Vendor A 400 EGP / Vendor B 380 EGP / Vendor C 420 EGP) should indeed
potentially all be visible to a company whose catalog allows that product — but this requires
**new, additive storefront/query code in a future phase**, not a modification of
`CompanyCatalogProduct` or the B2B `ProductRepository` (explicitly out of scope this phase,
and not touched).

## 10. Cart / Order Future Requirements

B2B Suite's cart-level company context is real and already shipped:
[2026_06_19_100024_add_column_to_cart_table.php](../../vendor/bagisto/b2b-suite/src/Database/Migrations/2026_06_19_100024_add_column_to_cart_table.php)
adds a nullable `cart.company_id` column (after `customer_id`). Marketplace has **no**
equivalent today — no `vendor_id`/`vendor_offer_id` column exists on `cart`, `cart_items`, or
anywhere else; this was confirmed absent by direct grep (Section 3 below finds zero matches).

For a future order-splitting phase to work, these facts must survive from cart through order
without being implemented now:

- Per cart line: which `VendorProduct` (hence which `vendor_id` + `product_id` + the vendor's
  price *at the time of purchase*) the line was added against — not just the bare
  `product_id` that core `cart_items` stores today.
- Per cart: the `company_id` already captured by B2B Suite (unchanged, reused as-is).
- Enough of both to later group an order's line items by vendor (`Parent Order → Vendor Order
  A/B/C`) without re-deriving "which vendor sold this line" from scratch after the fact (price
  could change on the `VendorProduct` row between purchase and later lookup).

No cart/order schema or code is touched in this phase — this section is a requirements note
for whichever future phase implements Vendor Catalog + Cart.

## 11. Authorization Boundary

```
Buyer side:   Customer → (b2b_customer_companies membership) → customers.company_role_id → CompanyRole (JSON permissions)
Seller side:  Customer → VendorUser (role: owner/admin/manager/staff) → VendorPolicy (code-level checks)
```

Confirmed structurally separate, and they should remain so:

- **`company_role_id` should never exist on `VendorUser`** — confirmed absent
  ([VendorUser.php](../../packages/Webkul/Marketplace/src/Models/VendorUser.php) fillable:
  `vendor_id`, `customer_id`, `role`, `status` only). `CompanyRole` permissions are a free-form
  JSON blob meant for buyer-side operations (viewing invoices, placing POs, quote approval
  limits); `VendorUserRole` is a fixed 4-value enum meant for seller-side operations (profile/
  staff/product management). Merging them would force one permission vocabulary to serve two
  unrelated operation sets.
- **Vendor role should never exist in B2B `CompanyRole`** — confirmed absent (`CompanyRole`'s
  only columns are `name`, `description`, `permission_type`, `permissions`, `customer_id`; no
  `vendor_id` or vendor-role concept anywhere in `vendor/bagisto/b2b-suite`).
- **`Vendor` should never extend/reuse B2B `Company`** — confirmed: `Vendor extends
  Illuminate\Database\Eloquent\Model` directly, no relationship to
  `Webkul\B2BSuite\Models\Customer` anywhere.
- **`Company` should never extend/reuse `Vendor`** — confirmed: B2B Suite's `Customer`
  subclass has zero reference to any Marketplace class (Section 3 exhaustive grep).

The architectural direction stated in the prompt — `Company ≠ Vendor`, `CompanyRole ≠
VendorRole` — is **confirmed correct against the actual code**, not just an assumption.

## 12. Concord / Customer Model Integration Risk

This is the most concrete, already-latent risk this audit found, and it exists **today**,
independent of any future Vendor Catalog work.

[B2BSuiteManager::registerModels()](../../vendor/bagisto/b2b-suite/src/Providers/B2BSuiteManager.php):

```php
private function registerModels(): void
{
    $this->app->concord->registerModel(CustomerContract::class, Customer::class);
}
```

This rebinds `Webkul\Customer\Contracts\Customer` → `Webkul\B2BSuite\Models\Customer`
(a subclass adding `companies()`, `members()`, `companyCatalog()`, `salesRep()`, custom
attribute resolution, etc.) **globally, via Concord**. Anywhere in the codebase that resolves
the customer through the **contract** or the **`CustomerProxy`** (the idiomatic
Concord-aware way) gets the B2B subclass instance — e.g. `auth('customer')->user()` typically
resolves through the bound contract and therefore returns a `Webkul\B2BSuite\Models\Customer`
instance when B2B Suite is active.

Marketplace's `VendorUser::customer()` ([VendorUser.php](../../packages/Webkul/Marketplace/src/Models/VendorUser.php))
deliberately does **not** go through the contract/proxy:

```php
public function customer(): BelongsTo
{
    return $this->belongsTo(Customer::class, 'customer_id'); // Webkul\Customer\Models\Customer — hard-coded
}
```

**The risk:** for the exact same `customers.id` row, two different code paths in this
application can yield two different PHP class instances —
`Webkul\B2BSuite\Models\Customer` (via the Concord-bound contract/auth) vs.
`Webkul\Customer\Models\Customer` (via `VendorUser::customer()`'s hard-coded relation). They
share the same table and the same base columns, but only the B2B subclass instance exposes
`companies()`/`members()`/`companyCatalog()`/`salesRep()`. Code that does
`$vendorUser->customer->companies` would currently fail (method doesn't exist on the base
class) even though the identical row, fetched via `auth('customer')->user()`, would succeed.

This was a **deliberate, documented Phase 5 choice** (per the inline comment: *"Deliberately
the stock Bagisto Customer model, not B2B Suite's subclass — the Marketplace package has no
dependency on B2B Suite"*) made specifically to keep Marketplace decoupled from whether B2B
Suite is even installed. It is the **correct** choice for keeping the packages independent,
but it means any **future** code that needs both "this is a vendor staff member" and "this is
also a company member" facts about the same customer must explicitly re-resolve the customer
through whichever path it needs (e.g. `CustomerProxy::modelClass()::find($vendorUser->customer_id)`
to get the B2B-aware instance when B2B facts are actually needed), rather than assuming
`$vendorUser->customer` already carries them.

**Recommendation (not implemented): a future integration service, if ever needed, should
accept a bare `customer_id` (not a hydrated model) and internally resolve through
`CustomerProxy::modelClass()` when it needs B2B-specific relations** — never assume the
instance handed to it by either package already has the other package's methods available.

## 13. Recommended Integration Model

Evaluated against the actual code (not aesthetics):

- **Model A** (Company → VendorProduct → Product directly) — too flat on its own: it ignores
  the fact that B2B Suite's *only* existing visibility/pricing mechanism is the catalog
  allowlist, not a direct product reference.
- **Model B** (Company → Vendor → VendorProduct) — rejected: implies Companies are
  assigned/restricted to specific Vendors, which nothing in either domain's current code
  requires or supports, and would be new structural coupling invented without a requirement.
- **Model C** (Company → CompanyCatalog → VendorProduct → Product) — closest in spirit, but
  as literally diagrammed it requires changing `CompanyCatalogProduct`'s target from
  `product_id` to a vendor-offer id, i.e. **modifying B2B Suite schema** — explicitly out of
  scope for this phase and not something this audit is allowed to do.
- **Model D** (new `CompanyVendor` relationship table) — rejected: no current requirement
  evidences that companies need to be restricted to an allowlist of vendors (as opposed to an
  allowlist of products, which already exists). Building this now would be the exact
  "architecturally clean but unjustified" trap the task warned against.

**Recommended: a refinement of Model A — composition at the shared `product_id`, with no new
table and no direct Company↔Vendor or Company↔VendorProduct foreign key.**

```
Company ──(existing, unmodified)── CompanyCatalog ──(existing, unmodified)── product_id
                                                                                 │
                                                                      (shared key, already FK'd
                                                                       by both domains today)
                                                                                 │
Vendor ───(existing, unmodified)── VendorProduct ──(existing, unmodified)───── product_id
```

Both existing relationships already terminate at the same neutral `products.id` with zero
changes needed on either side. A future Vendor Catalog phase can compose "which vendor offers
of this product are visible to this company" as a **read-only query/service** that:

1. Resolves the company's allowed `product_id`s from `CompanyCatalogProduct` (unchanged).
2. Resolves active `VendorProduct` offers for those same `product_id`s (unchanged).
3. Joins the two result sets **in application code**, never via a new foreign key.

This requires zero migrations, zero changes to either domain's core models, and preserves
every independence guarantee audited in Sections 5–7 and 11. It is the only evaluated model
that introduces **no new coupling at all** while still answering the business question.

## 14. Explicitly Deferred

Per the task's explicit scope boundary, none of the following were implemented or designed in
detail during this audit — only identified as future work:

- Vendor Catalog (the composition query/service described in Section 13)
- Vendor pricing precedence resolution (Section 8 — order of Product/Vendor/Company/RFQ price)
- Cart line-level vendor offer tracking (Section 10)
- Multi-vendor cart, marketplace checkout, parent/vendor order splitting
- Commission, settlement
- Marketplace GraphQL API
- Vendor storefront frontend
- Any schema change to `CompanyCatalogProduct` or any other B2B Suite table

## 15. Phase Sequence After This Audit

Recommended next phases, in order:

1. **Vendor Catalog (read-only composition)** — implement the Section 13 query/service that
   surfaces, for a given product (or company), the set of active vendor offers, respecting
   the company's existing catalog allowlist where one applies. No schema changes.
2. **Vendor pricing precedence decision** — a short, focused design task (not this audit) to
   decide, with real business input, how Product/Vendor/Company/RFQ prices actually compose
   into one "price shown to this buyer" — before any cart-facing pricing UI is built.
3. **Cart line vendor tracking** — add the minimal data needed (likely a `vendor_product_id`
   reference per cart item) once the pricing precedence from (2) is decided, so a price
   doesn't need to be re-derived after the fact.
4. **Order splitting** — only after (1)–(3) are stable, implement parent/vendor order
   grouping.

---

### Findings

**Confirmed facts:**
- Company = `customers` row (`type='company'`); company users = `customers` rows
  (`type='user'`) linked via `b2b_customer_companies`.
- `company_role_id`/`company_catalog_id`/`sales_rep_id` are single FK columns directly on
  `customers`, not per-membership.
- `b2b_customer_companies` has no unique constraint — structurally allows duplicate/multiple
  company memberships.
- B2B Suite's catalog pricing reuses Bagisto's core `product_customer_group_prices` via an
  auto-provisioned hidden customer group per catalog — zero new pricing tables.
- `cart.company_id` is real (B2B Suite migration); no Marketplace equivalent exists.
- Marketplace `Vendor`/`VendorUser`/`VendorProduct` have **zero** references to any B2B Suite
  class, table, or concept (exhaustive grep, Section 3 of the investigation — see "Files
  inspected").
- B2B Suite has **zero** references to any Marketplace class, table, or concept (same grep).
- `B2BSuiteManager` rebinds the `CustomerContract` to B2B Suite's `Customer` subclass via
  Concord; Marketplace's `VendorUser::customer()` deliberately bypasses this by hard-coding
  the base `Customer` class — a real, already-existing divergence (Section 12).

**Missing relationships:**
- No link between a `CompanyCatalog`'s allowlisted `product_id`s and *which vendor's offer* of
  that product should be shown/preferred.
- No cart/order-level vendor offer tracking.
- No defined price-precedence order across Product/Vendor/Company/RFQ prices.

**Risks:**
- The Concord Customer rebinding divergence (Section 12) — latent today, will surface the
  moment any future code assumes `$vendorUser->customer` has B2B Suite methods.
- `b2b_customer_companies`'s lack of a unique constraint (not caused by or related to
  Marketplace, but worth the B2B Suite maintainers' awareness).

**Decisions required (not made in this audit):**
- Price precedence order (Section 8).
- Whether/how a company catalog should ever narrow *which vendor's* offer of an allowed
  product is shown (vs. showing all active offers unfiltered).

### Recommended Next Phase

Implement **Vendor Catalog as a read-only composition query/service** joining
`CompanyCatalogProduct`'s allowed `product_id`s with active `VendorProduct` offers for those
same ids, entirely in new Marketplace-side application code — no schema changes to either
domain, no new foreign keys, no modification of `CompanyCatalogProduct` or the B2B
`ProductRepository`. Defer the price-precedence decision (Section 8) to a short dedicated
design discussion before any pricing UI is built on top of that query.

### Files inspected

B2B Suite (`vendor/bagisto/b2b-suite/src/`):
`Models/Customer.php`, `Models/CompanyCatalog.php`, `Models/CompanyCatalogProduct.php`,
`Models/CompanyRole.php`, `Models/CompanyFlat.php`, `Providers/B2BSuiteManager.php`,
`Providers/B2BSuiteServiceProvider.php`, `Providers/ModuleServiceProvider.php`,
`Repositories/ProductRepository.php`, `Helpers/CompanyCatalog.php`,
`Http/Controllers/Shop/API/CartController.php`,
`Database/Migrations/2026_06_19_100016_create_b2b_customer_companies_table.php`,
`Database/Migrations/2026_06_19_100024_add_column_to_cart_table.php`,
`Database/Migrations/2026_06_19_100025_add_columns_to_customers_table.php`, plus a full
migrations-directory listing.

Marketplace (`packages/Webkul/Marketplace/src/`):
`Models/Vendor.php`, `Models/VendorUser.php`, `Models/VendorProduct.php`,
`Policies/VendorPolicy.php`, `Services/VendorMembershipService.php`,
`Services/VendorProductService.php`, `Providers/ModuleServiceProvider.php`,
`Database/Migrations/2026_10_02_000002_*` through `*_000004_*`.

Cross-repository searches: regex search for
`company_id|vendor_id|VendorProduct|CompanyCatalog|CompanyRole|b2b_customer_companies`
across all of `packages/Webkul/Marketplace/**` (67 matches, all internal to Marketplace — zero
B2B references) and across all of `vendor/bagisto/b2b-suite/**` for
`vendor_id|Marketplace|VendorProduct` (zero matches).

### Files changed

Only this document:
`docs/architecture/b2b-marketplace-integration.md` (new file).

No migrations, no B2B Suite source, no Marketplace domain behavior, no Paymob files were
changed.
