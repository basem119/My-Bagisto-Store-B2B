# Marketplace Domain Architecture (Phase 5 + Phase 6)

Status: Core domain (Phase 5) plus vendor lifecycle, onboarding, membership enforcement, and
the Vendor Portal/Admin HTTP foundation (Phase 6) are implemented. Checkout/fulfillment/
commission/storefront/catalog UI remain documented here as boundaries only — not yet
implemented (see "Deferred" at the end). Sections below are annotated `(Phase 6)` where a
Phase 6 decision extended or superseded the original Phase 5 text; unannotated text is
unchanged Phase 5 architecture.

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

### 2a. Vendor lifecycle transitions (Phase 6)

The enum also owns the **transition graph** — the single place that decides which status
changes are valid (`VendorStatus::allowedTransitions()` / `canTransitionTo()`):

```
pending   -> active (approve), rejected (reject)
active    -> suspended, inactive
suspended -> active (reactivate)
inactive  -> active (reactivate)
rejected  -> (terminal; no further transitions)
```

`VendorService` (renamed Phase 5's `register()` to `apply()`, to match the onboarding
language used everywhere else — `VendorApplicationRequest`, the `/vendor/apply` route) is
the **only** place `vendors.status` is allowed to change. Every transition goes through a
shared `transition()` helper that calls `guardTransition()` (which defers entirely to
`VendorStatus::canTransitionTo()` — no hard-coded `$allowedFrom` arrays scattered around),
wraps the mutation + `VendorStatusHistory` row in `DB::transaction()`, then dispatches the
corresponding event and sends a best-effort owner notification **outside** the transaction
(a notification failure must never roll back a lifecycle transition).

**Decision — no separate `APPROVED` enum case:** the task's lifecycle diagram shows
`pending → approved → active` as two steps, but this implementation treats "approve" as a
direct `pending → active` transition, captured via the existing `approved_at` timestamp
rather than an intermediate status. Adding a new enum case would be redesigning Phase 5
architecture without a concrete defect, which Phase 6 was explicitly told not to do; the
two-step *language* in the spec is fully satisfied by `approved_at` plus the status history
row recording the `pending → active` event.

**Decision — ownership transfer is explicitly deferred**, not partially implemented. A
vendor's single `owner` `VendorUser` row is set once, at application time
(`VendorService::apply()`), and nothing in Phase 6 allows reassigning it:
`VendorMembershipService::addMember()`/`assignRole()` both unconditionally reject the
`owner` role. This was chosen over a partial transfer mechanism per the task's explicit
instruction: "If ownership transfer is not necessary yet, explicitly defer it rather than
implementing a partial solution."

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

**(Phase 6)** Member `status` is now an operational gate, not just a data point: both
`VendorPolicy` and the Vendor Portal's `EnsureVendorContext` middleware require
`VendorUserStatus::ACTIVE`, resolved via `VendorMembershipService::isActiveMember()`. A
`suspended`/`invited` membership row still exists (so re-activation doesn't need to recreate
it) but grants zero access — verified by both a service-level assertion and a real HTTP
request returning 403 for a suspended member.

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

**(Phase 6)** `VendorPolicy` was extended so every check (`view`, `update`, `manageMembers`,
`manageProducts`) routes through a single `activeMembershipFor(Customer, Vendor): ?VendorUser`
helper — Phase 5 only checked row existence; Phase 6 also requires `status === ACTIVE`.
Verified: owner/admin can `update()`, manager/staff can `view()` but not `update()`, and a
suspended member can do neither, all confirmed with real cross-vendor HTTP requests (not
just unit assertions).

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

**(Phase 6)** Phase 5 deferred all HTTP; Phase 6 built both sides on top of the unchanged
Phase 5 services, exactly as this section predicted:

- **Platform Admin** — `admin/marketplace/vendors` (list/view/approve/reject/suspend/
  reactivate), guarded by the existing `admin` + `Bouncer` middleware and 7 new
  `marketplace.*` ACL nodes (`Config/admin/acl.php`). `Http\Controllers\Admin\VendorController`
  calls `VendorService`/`VendorMembershipService` only — it never writes `vendors.status`
  directly. The vendor-view action also surfaces `statusHistories` and `members` (Step 16's
  "inspect vendor members / status history" requirement) without a separate dashboard.
- **Vendor Portal** — `vendor/apply` (public, onboarding) and a `{vendor:slug}` group
  (`dashboard`, `profile`, `team`) guarded by the existing `customer` guard plus a new
  `Http\Middleware\EnsureVendorContext` middleware. The middleware never trusts a vendor id
  from the URL: it relies on Laravel route-model-binding to resolve `{vendor:slug}` into an
  actual `Vendor` instance, then calls `VendorMembershipService::isActiveMember()` before
  letting the request reach any controller. Unauthenticated requests redirect to
  `customer.login`; authenticated-but-not-a-member (or suspended) requests get a 403.

The same Phase 5 services are reused from both contexts unmodified in their core
invariants; the boundary is enforced by *who is allowed to call which service method and
through which middleware stack*, not by duplicating logic per portal.

### Route-model-binding gotcha (Phase 6)

Konekt Concord auto-registers an **explicit** route binder for every Concord model, keyed by
its short parameter name (here `"vendor"`). Laravel's `Router::substituteBindings()` checks
explicit binders *before* falling back to implicit per-controller-type-hint binding, and
Concord's explicit binder always calls `resolveRouteBinding($value)` with no `$field` —
which silently ignores the `{vendor:slug}` binding-field syntax and falls back to primary-key
lookup. Fixed by overriding `Vendor::resolveRouteBinding($value, $field = null)` to
disambiguate by value shape when `$field` is absent: numeric → primary key (admin routes use
plain `{vendor}`), non-numeric → `slug` (portal routes use `{vendor:slug}`). This is the
general fix for *any* Concord-registered model that needs slug-based routing alongside
id-based admin routing.

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
                           VendorReactivated (Phase 6), VendorMemberAdded, VendorMemberRemoved,
                           VendorRoleChanged (Phase 6),
                           VendorProductAttached, VendorProductDetached
        Notifications/     VendorApplicationReceived, VendorStatusChanged (Phase 6 — plain
                           Illuminate\Notifications\Notification, Notifiable Customer/Admin)
        Policies/          VendorPolicy
        Http/              (Phase 6)
            Controllers/Admin/    VendorController
            Controllers/Vendor/   Controller (base), OnboardingController, DashboardController,
                                  ProfileController, TeamController
            Middleware/           EnsureVendorContext
            Requests/             VendorApplicationRequest, VendorProfileUpdateRequest,
                                  AddVendorMemberRequest, UpdateVendorMemberRoleRequest,
                                  VendorStatusActionRequest
        Routes/            (Phase 6) web.php, admin-routes.php, vendor-routes.php
        Resources/         (Phase 6) views/{admin,vendor}/..., lang/en/app.php
        Config/            (Phase 6) admin/acl.php — 7 marketplace.* ACL nodes
        Providers/         ModuleServiceProvider (Concord), MarketplaceServiceProvider
                           (policy/routes/views/translations/ACL/middleware-alias registration)
        Database/Migrations/
```

This mirrors the existing `packages/Webkul/<Name>/src/...` convention used by every other
package in this repository (including the custom `Paymob` package), registered the same way
— a `path` Composer repository + a `ModuleServiceProvider` listed in `config/concord.php`'s
`modules` array. No new structural convention was invented.

## 15. Marketplace Product Discovery / Storefront (Phase 8)

A public, read-only composition layer over the unchanged Phase 5/7 schema — zero migrations.
Adds `Services/ProductDiscoveryService`, `Http/Controllers/Shop/MarketplaceController`,
`Routes/storefront-routes.php` (`/marketplace`, `/marketplace/products/{urlKey}`), and two
Blade views (`shop/index`, `shop/show`). No `customer` guard middleware — mirrors Bagisto's
own public product/category browsing (confirmed via `store-front-routes.php`: product/
category pages carry no auth requirement).

### Eligibility rule (and a deliberate deviation from core Bagisto)

A product is marketplace-discoverable only when, for the **current channel + locale**:

```
product_flat.status = 1          (product enabled — reused verbatim from core)
AND vendor_product.status = active
AND vendor_product.quantity > 0   (see Inventory boundary below)
AND vendor.status = active
```

**Deliberately does NOT also require `product_flat.visible_individually = 1`.** That flag is
core Bagisto's OWN "should this appear standalone in Bagisto's native catalog/search" signal,
and is `0` for every configurable-variant child product (verified directly against seeded
data: every `products` row with a non-null `parent_id` has `visible_individually = 0` in its
`product_flat` row). Phase 7 already established that vendors attach `VendorProduct` offers
to *concrete sellable* products — which, for configurable items, are necessarily the variant
rows (Phase 7 explicitly rejects attaching an offer directly to a configurable *parent*).
Requiring `visible_individually = 1` would therefore silently exclude almost every real-world
vendor offer from marketplace discovery. Marketplace discovery is an **additive, independent
view** driven by "does an eligible vendor offer exist for this product_id", not a duplicate of
core Bagisto's own catalog browsing — so reusing that specific flag does not apply here, only
`status` does. This was discovered by inspecting seeded `product_flat` rows, not assumed.

### Product deduplication

The listing query is `GROUP BY product_id`, never one row per `VendorProduct` — verified with
an explicit test (two active vendors offering the same product still produce exactly one
listing row, with `MIN(price)` as the displayed "from" price and `COUNT(DISTINCT vendor_id)`
as the vendor count). Sorting/display never reads a single arbitrary joined `VendorProduct`
row's price — only the aggregate.

### Pricing boundary (unchanged)

The listing's "from price" and the detail page's per-offer prices are plain reads of
`VendorProduct.price` — no precedence logic, no composition with `product_customer_group_prices`
or B2B Company Catalog pricing, no RFQ integration. `product_customer_group_prices` is never
queried or modified by this service.

### Inventory boundary (unchanged)

`VendorProduct.quantity = 0` means **hidden from discovery entirely**, not "visible but
unavailable" — a deliberate, documented choice for this foundation phase (there is no cart/
checkout yet to meaningfully show an unavailable offer against), not a new inventory system.
`product_inventories.vendor_id` is never read by this service, consistent with the Phase 6.x
audit's instruction that it must not be interpreted as marketplace vendor ownership.

### Vendor identity (minimal, by design)

No dedicated public vendor profile page was built — only `vendor.name` is surfaced inline
next to each offer on the product detail page, satisfying "a simple vendor identity/display
is sufficient" without building the explicitly-deferred full vendor storefront.

## 16. Multi-Vendor Cart (Phase 9)

Adds `Services/MarketplaceCartService` (resolve/validate a vendor offer, delegate persistence
to Bagisto's own `Cart` facade — never a second cart system), `Type/MarketplaceAwareSimple`,
`Listeners/RevalidateVendorOfferQuantity`, one new route (`POST /marketplace/cart/add`), and
an "Add to Cart" form per offer row on the Phase 8 product detail page. **No migration** —
`cart_items.additional` (a pre-existing JSON column, confirmed unused by anything
marketplace-relevant) is the extension point.

### Where the selected offer is stored

`cart_items.additional` (JSON) gains three keys when a line represents a marketplace offer:
`vendor_product_id`, `vendor_id`, `product_id`. A normal, non-marketplace cart item (B2C or
B2B) simply never has these keys — `additional` is already nullable/free-form, so nothing
about the column's existing meaning for other product types changes.

### Cart item identity (the core risk this phase had to solve)

Bagisto decides whether two "add to cart" calls merge into one line or become two separate
lines via `ProductType::compareOptions()` — for `Simple` (and therefore for every concrete
product a `VendorProduct` can be attached to; Phase 7 already rejects configurable *parent*
offers) the stock implementation only compares `product_id`/`parent_id`. Verified directly:
two different vendors' offers of the same product would otherwise silently merge into one
line with an accumulated quantity and whichever price happened to win the merge — exactly
the bug this phase was told to prevent.

**Fix:** `Webkul\Marketplace\Type\MarketplaceAwareSimple extends Simple`, overriding
`compareOptions()` to also require `vendor_product_id` equality before falling through to the
parent check, bound over the container (`$this->app->bind(Simple::class,
MarketplaceAwareSimple::class)` in `MarketplaceServiceProvider::register()`) — the exact same
"rebind over the container" pattern B2B Suite itself already uses for `ProductRepository`/
`Customer`. `Webkul\Product\Type\Simple` is never edited. When neither side's data carries
`vendor_product_id` (every non-marketplace add), the comparison degrades to `null === null`
and falls through to the unmodified parent behavior — verified with an explicit test adding a
normal product immediately after two vendor-offer adds.

### Vendor price (and a second risk this phase had to solve)

`VendorProduct.price` is written into `base_price`/`price` (converted) during
`prepareForCart()` — but `Cart::collectTotals()` independently calls
`ProductType::validateCartItem()` on every cart read/update, and the stock implementation
unconditionally **recomputes** the price from `getFinalPrice()` (Bagisto's own base/
customer-group/catalog-rule price), silently overwriting the vendor's price the very next
time the cart page loaded. `MarketplaceAwareSimple::validateCartItem()` skips this
recomputation when `additional.vendor_product_id` is present (still runs the inactive-item
check), so the vendor's price survives every reload — verified by re-fetching the cart fresh
after `collectTotals()` ran and confirming the price was still the vendor's, not Bagisto's own.

### Quantity validation (two separate call sites)

1. **Add** — `MarketplaceCartService::assertSufficientQuantity()` sums whatever quantity of
   this *same* `vendor_product_id` is already in the cart, adds the newly requested quantity,
   and rejects if the total exceeds `VendorProduct.quantity`, before `Cart::addProduct()` is
   ever called.
2. **Update** (changing quantity on an existing cart line, Bagisto's existing `/cart/update`
   flow, shared by every item) — `Listeners\RevalidateVendorOfferQuantity`, hooked on the
   existing `checkout.cart.update.before` event, re-checks the new quantity against the
   *current* `VendorProduct.quantity` and throws to abort the update if it's exceeded.

Both are entirely independent of, and do not replace, Bagisto's own core inventory check
(`haveSufficientQuantity()`/`product_inventories`) — that still runs unmodified inside
`parent::prepareForCart()`. `product_inventories.vendor_id` is never read by this phase,
consistent with the Phase 6.x audit.

### B2B Company cart coexistence

`cart.company_id` is untouched — confirmed via direct inspection after an HTTP add-to-cart
request: the column exists, is nullable, and remains whatever B2B Suite's own existing logic
sets it to (`null` for a guest cart in this test, populated independently by B2B Suite for a
company customer's cart). Nothing in Phase 9 reads or writes `cart.company_id`, and no
Company ↔ Vendor relationship was introduced.

### Security (all enforced server-side, in `MarketplaceCartService::resolveEligibleOffer()`)

The submitted `price` is never read at all (not even accepted as a parameter). A submitted
`vendor_product_id` is re-resolved from the database and its `product_id` is compared against
the submitted/expected `product_id` — a mismatch (spoofed pairing) is rejected before any cart
mutation. An inactive offer, a non-active vendor, or a nonexistent `vendor_product_id` are all
rejected the same way. Verified with real HTTP requests (not just unit-level calls).

### Explicitly deferred (not solved in Phase 9)

Pricing precedence (vendor vs. company-catalog vs. RFQ price — `VendorProduct.price` is simply
what gets charged, full stop, for a marketplace line), checkout/order integration, order
splitting, inventory reservation/decrement (`VendorProduct.quantity` is never decremented by
adding to cart — only validated), vendor-identity display inside Bagisto's own Vue cart/
checkout UI (the cart page is otherwise completely unmodified; vendor name is only shown on
the Marketplace's own product detail page), `Virtual`/`Downloadable`/`Bundle`/`Booking`/
`Grouped` product types (only `Simple` was overridden — if a vendor ever offers one of those
types, the same merge/price risks this phase fixed for `Simple` would still apply there;
flagged as a known gap, not a silent one).

## 17. Rejected alternatives

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
- **(Phase 6) A new `VendorStatus::APPROVED` enum case** — rejected; see Section 2a.
  `approve()` is a direct `pending → active` transition, recorded via `approved_at` +
  status history, not a new intermediate status.
- **(Phase 6) Ownership transfer** — rejected for this phase (deferred, not partially
  built); see Section 2a. Implementing "reassign owner" as a half-measure (e.g., allowing
  role assignment to `owner` without a transactional old-owner-demotion/new-owner-promotion
  pair) would risk a vendor ending up with zero or two owners.
- **(Phase 6) Manual vendor lookup + `setParameter()` in `EnsureVendorContext`** — rejected
  in favor of Laravel's native `{vendor:slug}` route-model-binding (see Section 11); the
  middleware now trusts the already-resolved `Vendor` instance instead of re-querying by a
  raw slug string pulled off the route.
- **(Phase 9) A `marketplace_cart_items` table** — rejected; `cart_items.additional` (an
  existing, unused-by-anyone-else JSON column) already satisfies every requirement
  (survives persistence/reload, works for guest and customer carts, coexists with
  `cart.company_id`) with zero schema change.
- **(Phase 9) Editing `Webkul\Product\Type\Simple` directly** — rejected; would be a global,
  unscoped behavior change to every Simple product in the application (B2C and B2B alike).
  Container-rebinding a subclass (`MarketplaceAwareSimple`) confines both overrides to
  exactly the marketplace-add case (guarded by `vendor_product_id` presence) and mirrors a
  pattern already established in this codebase by B2B Suite itself.
- **(Phase 9) A global "vendor price always wins" pricing rule** — not introduced; the price
  substitution in `MarketplaceAwareSimple` only ever applies to a cart line that was created
  through the marketplace add-to-cart flow (carries `vendor_product_id`). A normal product
  added via Bagisto's own `/cart/add` is priced exactly as before.

## Deferred (explicitly NOT built in Phase 5, 6, 7, 8, or 9)

Vendor pricing precedence/composition engine, marketplace checkout, parent/vendor order
splitting, fulfillment, RFQ marketplace integration, independent PO, commissions,
settlements, payouts, GraphQL marketplace API, ownership transfer, full public vendor
storefront/profile pages, marketplace-specific price sorting (current listing sorts by name;
if lowest-price sorting is added later it must use the same `MIN(price)` aggregate already
used for display), inventory reservation/decrement on add-to-cart, vendor-identity display
inside Bagisto's own cart/checkout UI, `Virtual`/`Downloadable`/`Bundle`/`Booking`/`Grouped`
vendor-offer cart support (only `Simple` was extended in Phase 9), final
security/regression/performance/payment E2E testing.

Phase 6 resolved: vendor onboarding HTTP flow, admin vendor lifecycle management HTTP,
Vendor Portal foundation (dashboard/profile/team), vendor-scoped HTTP authorization
middleware, active-membership enforcement in `VendorPolicy`, lifecycle notifications.

Phase 7 resolved: vendor offer (VendorProduct) CRUD via the vendor portal, admin offer
oversight, product-eligibility validation for attaching offers, cross-vendor offer-ownership
enforcement.

Phase 8 resolved: public marketplace product discovery/listing, product detail page with all
eligible vendor offers, product deduplication across multiple vendor offers, category/vendor/
search filtering, pagination.

Phase 9 resolved: marketplace offer → Bagisto cart integration (add-to-cart entry point,
cart-item identity distinguishing different vendors' offers of the same product, vendor price
surviving `prepareForCart()` AND `collectTotals()`/`validateCartItem()`, add- and
update-quantity revalidation against `VendorProduct.quantity`, server-side vendor/offer/price
spoofing prevention).
