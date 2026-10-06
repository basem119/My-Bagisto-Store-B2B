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

> **(Phase 10 update)** The flat `additional.vendor_product_id`/`vendor_id` keys described
> above were nested under `additional.marketplace` in Phase 10 (adding `vendor_sku` and
> `vendor_price` snapshot fields) — see Section 17. This is an additive refinement of the
> shape, not a change to the storage mechanism (`cart_items.additional` is still the same
> pre-existing JSON column).

## 17. Checkout & Order Boundary (Phase 10)

Takes the vendor-aware cart from Phase 9 through Bagisto's existing, **unmodified** checkout
and produces a vendor-aware order — no second checkout, no second order system, no order
splitting.

### The discovered Cart → Order flow

`OnepageController::storeOrder()` calls `Cart::collectTotals()`, then builds
`$data = (new OrderResource($cart))->jsonSerialize()`, then
`OrderRepository::create($data)`. `OrderResource`/`OrderItemResource` read directly off the
already-persisted `Cart`/`CartItem` rows — **no new client input is accepted at order-creation
time**. Critically, `OrderItemResource::toArray()` already does
`'additional' => array_merge($this->resource->additional ?? [], [...])` — `cart_items.additional`
is copied into `order_items.additional` **verbatim, with zero Phase 10 code required** for that
copy to happen. `order_items.additional` is the same pre-existing nullable JSON column type as
`cart_items.additional` (confirmed by inspecting the migration before writing anything) — no
migration was needed.

### Purchase-time snapshot (why the shape changed from Phase 9)

Phase 9's `additional` only carried `vendor_product_id`/`vendor_id`/`product_id` — enough to
trace an order item back to an offer, but **not** enough to survive the offer changing later
(`vendor_sku` and `vendor_name` are mutable; relying on a live `VendorProduct`/`Vendor` lookup
after the fact would make historical orders show today's values, not what was purchased).
`MarketplaceAwareSimple::prepareForCart()` now additionally snapshots `vendor_name` and
`vendor_sku` into `additional.marketplace` at add-to-cart time (price was already correctly
sourced from `VendorProduct.price` since Phase 9 — `vendor_price` is stored explicitly
alongside it purely so order-processing code never has to infer which price column is "the
vendor's"). Because this snapshot is taken once, at add-to-cart time, and never re-read from
`VendorProduct` afterward, changing `VendorProduct.price`/`vendor_sku` after an order exists
has **zero effect** on that order — verified explicitly: changed a purchased offer's price to
999 and SKU to a new value after order creation, re-fetched the order, confirmed it still
showed the original 300/XA-SKU.

### Order price — never recalculated

`order_items.price`/`base_price` are copied straight from `cart_items.price`/`base_price` by
the stock `OrderItemResource` (unmodified) — exactly the cart's checkout-time price, which for
a marketplace item is already the vendor's price (Phase 9). Phase 10 adds no price computation
of its own anywhere in the order-creation path.

### Pre-order validation — reused, not duplicated

`Cart::collectTotals()` calls `Cart::validateItems()`, which calls
`ProductType::validateCartItem()` on every item and **removes** any item that reports itself
inactive — this is Bagisto's own existing "strip stale items before checkout" mechanism
(already relied upon in Phase 9 to skip the vendor-price recomputation). Phase 10 extends
`MarketplaceAwareSimple::validateCartItem()` to also re-check, at the moment checkout runs,
that the `VendorProduct` still exists and is active, its `Vendor` is still active, and the
cart quantity does not exceed the vendor's current `VendorProduct.quantity` — flagging the
item inactive (and therefore removed from the cart before `OrderResource` ever sees it) if
any of those no longer hold. **No new checkout validation step, event listener, or service
was added for this** — it reuses the exact extension point `Cart::validateItems()` already
calls. Verified explicitly: suspended a vendor after adding their offer to cart, called
`Cart::collectTotals()` (what `storeOrder()` calls first), confirmed the item was stripped and
the cart left empty.

### Multi-vendor order (not split)

A single cart with three marketplace lines (`Product X` from Vendor A, `Product X` from
Vendor B, `Product Y` from Vendor A) produces **one** Bagisto order with **three** order
items, each independently carrying its own `additional.marketplace` snapshot — verified
explicitly, including the same-product-different-vendor case (both order items correctly
reference `product_id` = Product X, with different `vendor_id`/`vendor_sku`/price). No
`marketplace_orders`/`vendor_orders` table was created; order splitting remains a later
phase's responsibility.

### Admin visibility

A small read-only panel (vendor name/SKU/offer id/purchase price) was added to the existing
admin order-item display — **without editing the core Admin Blade view file** — by listening
to Bagisto's own `bagisto.admin.sales.order.list.item.after` `view_render_event` hook (the
same extension mechanism already used elsewhere in core Bagisto for this exact view) and
supplying a Marketplace-owned partial. The panel renders nothing for any order item without
`additional.marketplace` — normal orders are completely unaffected. Verified over real HTTP
that the panel shows the *original* purchase-time price, not a since-changed one.

### B2B Company order compatibility

`orders` has **no** `company_id` column at all (confirmed by inspecting
`create_orders_table.php` and `OrderResource::toArray()` — B2B Suite's company context is a
cart-level concept only, per the Phase 9 finding; it is not itself carried onto the order by
core Bagisto). Nothing in Phase 10 changes that. A company customer's order and a marketplace
order item's vendor identity are simply two independent facts that can both be true of the
same order, exactly as they were two independent facts about the same cart in Phase 9 — no
Company ↔ Vendor relationship was introduced.

### Inventory (unchanged boundary)

`VendorProduct.quantity` is validated (both at add-to-cart and again at checkout-time, see
above) but **never decremented** by order creation — confirmed by re-reading the
`VendorProduct` rows after placing a test order and finding their `quantity` unchanged. Core
Bagisto's own `product_inventories`/inventory-index deduction (`OrderRepository::manageInventory()`)
runs completely unmodified and independently, exactly as it does for any non-marketplace
order. Race conditions between two customers checking out against the same limited
`VendorProduct.quantity` are **not** resolved atomically in this phase (no row-locking/
reservation) — explicitly flagged as a limitation for a future marketplace-inventory phase,
not silently assumed away.

### Payment / Paymob

Not touched. The test order in this phase used Bagisto's core `cashondelivery` payment method
(ships with Bagisto, requires no external gateway/credentials) specifically so Paymob would
need zero involvement to validate order creation. Paymob's own checkout flow was not
re-tested end-to-end in this phase (no regression was introduced that would require it — no
Paymob file was read or modified), but is unaffected in principle since marketplace metadata
flows entirely through `cart_items.additional`/`order_items.additional`, which Paymob's
integration does not touch.

## 18. Vendor Order Management & Fulfillment Foundation (Phase 11)

A **filtered view of the canonical Bagisto `orders`/`order_items`**, not a second order
system. No new `vendor_orders`/`marketplace_orders` table was created; everything in this
section is read-only query composition over tables that already existed after Phase 10.

### Ownership source of truth

Vendor ownership of an order item is determined **solely** by the historical
`order_items.additional.marketplace.vendor_id` snapshot written at checkout time (Phase 10)
— never by `product_id` (one product can have offers from multiple vendors) and never by a
live `VendorProduct` lookup (the offer may since have been edited or deleted). This mirrors
Phase 10's own purchase-time-snapshot principle applied to a new read path.

Ownership is at the **order-item level**, not the order level. A single Bagisto `Order` may
contain items from several vendors; a vendor's portal view returns only that vendor's own
items within any given order — confirmed with a real 3-item/2-vendor order (`order_id=1`,
items `#1`/`#3` → Vendor A, item `#2` → Vendor B): Vendor A's order-detail HTTP response
contains `XA-SKU`/`YA-SKU` and never `XB-SKU`; Vendor B's contains only `XB-SKU`.

### Query mechanics

`VendorOrderService` (`packages/Webkul/Marketplace/src/Services/VendorOrderService.php`)
filters directly in SQL using Laravel's JSON-path query syntax —
`->where('order_items.additional->marketplace->vendor_id', $vendor->id)` — confirmed (before
writing any controller/view code) to translate correctly to MySQL's
`JSON_UNQUOTE(JSON_EXTRACT(...))` and match real rows. `paginateVendorOrders()` groups by
order and counts *that vendor's* items only (`vendor_item_count`); `getVendorOrderItems()`
returns only that vendor's own `OrderItem` rows for a specific order; `vendorHasItemsInOrder()`
is the authorization gate for the detail page; `vendorOwnsOrderItem()` is a single-item
ownership check available for any future write action.

### Authorization

Enforced at the **query/service layer**, not via `Order::find($id)` followed by a PHP-level
ownership check — the vendor-order-detail query itself only ever returns rows matching the
vendor's `additional.marketplace.vendor_id`, so there is no code path that can accidentally
leak another vendor's item data even if a future refactor forgets an `if`.

`OrderController` (`Http/Controllers/Vendor/OrderController.php`) reuses the exact same
building blocks established in Phases 6-9: `EnsureVendorContext` (route middleware, proves
active membership in the URL's `{vendor:slug}`) + `$this->authorize('view', $vendor)`
(`VendorPolicy`, same gate already used by the dashboard/profile/team "read" actions — no
new policy ability was added since no new permission tier is needed for read-only order
visibility). A **second** guard, `assertVendorHasItemsInOrder()`, closes the gap
`EnsureVendorContext` cannot: proving membership in the vendor proves nothing about whether
*this specific order* contains any of that vendor's items — an order the vendor has zero
items in 404s, it does not render an empty/misleading page (same `assertOwnedByVendor`-style
pattern established for `VendorProduct` in Phase 7, applied here to orders).

### Order status / fulfillment state decision

**No new status field was added, anywhere.** Bagisto's `orders.status` remains a single
global value representing the whole order and is never written to by any marketplace code —
multi-vendor orders are never force-set to a status that would misrepresent another vendor's
items. The task's own escape hatch ("introduce marketplace-specific fulfillment state at the
OrderItem/vendor scope only if the existing Bagisto model cannot safely represent it") was
evaluated and found **not necessary**: `order_items` already carries independent, per-item
progress counters — `qty_ordered`, `qty_shipped`, `qty_invoiced`, `qty_canceled`,
`qty_refunded`, and a computed `qty_to_ship` (`OrderItem::canShip()` already checks
`qty_to_ship > 0` per item, with zero coupling to sibling items in the same order). These are
exactly what "vendor A has shipped, vendor B hasn't" needs to be representable, and they cost
zero new schema. The vendor order-detail view surfaces these existing counters per item.
Because no vendor-scoped shipment action exists yet (see below), every marketplace order
item's counters simply remain at their Phase-10 defaults (`qty_shipped = 0`) until a future
phase adds vendor-initiated fulfillment — this is accurately reflected in the UI rather than
inventing a fake "shipped" state.

### Shipment — deferred, with justification

Vendor-scoped shipment **creation** was investigated and is **explicitly deferred**, not
implemented. Inspection of `Webkul\Admin\Http\Controllers\Sales\ShipmentController` and the
`shipments`/`shipment_items` schema found shipment creation tightly coupled to
**platform-level, admin-managed `inventory_sources`**: every shipment requires a
`shipment.source` (an `inventory_source_id`), and `isInventoryValidate()` checks the
requested quantity against `product->inventories()->where('inventory_source_id', ...)->sum('qty')`
— core Bagisto's own warehouse/stock-location concept, not anything a `Vendor` owns or
controls in this codebase (same boundary already established in Section 2: vendor identity
is completely independent of `product_inventories`).

Letting a vendor create a shipment would therefore require either (a) exposing core
inventory-source selection to vendors — leaking platform operational/warehouse structure to
a tenant that has no relationship to it, or (b) building a parallel, vendor-only shipment
quantity/validation mechanism that duplicates `ShipmentController::isInventoryValidate()`'s
logic outside the core flow. Both were rejected: (a) is a wrong trust/ownership boundary, and
(b) is exactly the "uncontrolled replacement" the task warned against building instead of a
documented limitation. No shipment-creation route, controller action, or view was added in
this phase. The vendor order-detail page explicitly tells the vendor that shipment creation
is not yet available from the portal (`fulfillment-deferred` lang key) rather than silently
omitting the capability.

### Inventory

No change from Phase 10: `VendorProduct.quantity` continues to be validated (at add-to-cart,
at cart-quantity-update, at checkout) but never decremented by anything in this phase. Core
Bagisto's own inventory deduction (via `OrderItemRepository::manageInventory()` at order
creation, and via core `Shipment` creation) is completely unmodified and untouched, since no
vendor-facing shipment action was added that could call it.

### Admin oversight

A new read-only `/admin/marketplace/vendor-orders` page
(`Http/Controllers/Admin/VendorOrderController.php`) lists every marketplace order item
across all vendors (vendor name/SKU/price via the item's own historical snapshot, never a
live `Vendor`/`VendorProduct` lookup) with a link back to the **existing** admin order view.
Phase 10 already added a read-only marketplace panel to that order view
(`bagisto.admin.sales.order.list.item.after`) — this phase does not duplicate or modify it;
the new page is purely an additional cross-vendor index, confirmed via regression test to
still render the Phase 10 panel unchanged.

### B2B compatibility

Untouched. `orders.company_id` still does not exist (confirmed again by inspection); vendor
order ownership and B2B company context remain two independent, non-overlapping facts, as
established in Phase 10.

### Route-naming gotcha (new, worth recording for future phases)

Concord auto-registers an **explicit route-model-binding resolver for every Concord-registered
model, keyed by the route parameter's name** (not by the controller method's type-hint). Since
`Order` is Concord-registered and the route parameter was initially named `{order}`,
Laravel/Concord substituted a resolved `Order` instance into that slot even though the
controller method declared `int $orderId` — raising a `TypeError` at runtime (caught by HTTP
testing, not by `php -l`/static review). Fixed by naming the route parameter `{orderId}`
instead of `{order}`, which no longer matches Concord's registered binder key. This refines
the Phase 6 Concord route-binding note (which only covered the `{model:slug}` case): **any**
route parameter whose name matches a Concord model's short name can trigger this, regardless
of whether the controller actually wants an implicit model binding.

## 19. Rejected alternatives

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
- **(Phase 10) A dedicated `order_items` snapshot table / new columns** — rejected;
  `order_items.additional` (already a nullable JSON column, confirmed before writing any
  code) plus the fact that `OrderItemResource` already copies `cart_items.additional`
  verbatim meant zero schema change was needed.
- **(Phase 10) A new checkout-time marketplace validation service/event** — rejected;
  `Cart::validateItems()` (called by `Cart::collectTotals()`, called at the top of
  `storeOrder()`) already removes any cart item whose `ProductType::validateCartItem()`
  reports it inactive. Extending the existing Phase 9 `validateCartItem()` override to also
  re-check vendor/offer eligibility reused this exact mechanism instead of adding a parallel
  one.
- **(Phase 10) Editing the core Admin order-view Blade file** — rejected in favor of
  Bagisto's own `view_render_event`/`bagisto.admin.sales.order.list.item.after` extension
  hook, keeping 100% of Phase 10's changes inside `packages/Webkul/Marketplace` (consistent
  with every prior phase) while still surfacing the marketplace panel on the existing page.
- **(Phase 10) Order splitting / `marketplace_orders` / `vendor_orders` tables** — explicitly
  out of scope per the task; one Bagisto order with vendor-aware order items is the full
  extent of this phase.
- **(Phase 11) `Order::find($id)` + PHP-level ownership check** — rejected in favor of
  scoping the ownership check into the query itself (`additional->marketplace->vendor_id`
  WHERE clause); a PHP-level `if ($item->vendor_id !== $vendor->id) abort(403)` pattern was
  avoided so there is no code path that returns full unfiltered data and merely hides it.
- **(Phase 11) A new order/order-item status field for vendor fulfillment** — rejected;
  `order_items.qty_ordered`/`qty_shipped`/`qty_to_ship` (already present, already per-item)
  fully represent "vendor A shipped, vendor B hasn't" without any new schema.
- **(Phase 11) Vendor-initiated shipment creation reusing or duplicating core
  `ShipmentController`** — deferred, not rejected outright; see Section 18's "Shipment —
  deferred, with justification". Reuse was blocked by `inventory_sources` being a
  platform-level concept with no vendor ownership boundary; duplicating the core
  quantity-validation logic in a vendor-only path was rejected as an "uncontrolled
  replacement".
- **(Phase 11) `vendor_orders`/`marketplace_orders` tables** — explicitly out of scope per
  the task; every vendor order view in this phase is a live query over the existing
  `orders`/`order_items` tables.

## Deferred (explicitly NOT built in Phase 5, 6, 7, 8, 9, 10, or 11)

Vendor-initiated shipment/fulfillment actions (creation deferred — see Section 18), order
splitting (`marketplace_orders`/`vendor_orders` tables), vendor-specific shipping, commission,
settlement, payouts, vendor notifications beyond the existing lifecycle set, vendor pricing
precedence/composition engine, RFQ marketplace integration, independent PO, GraphQL
marketplace API, ownership transfer, full public vendor storefront/profile pages,
marketplace-specific price sorting (current listing sorts by name; if lowest-price sorting is
added later it must use the same `MIN(price)` aggregate already used for display), inventory
reservation/atomic decrement (neither at add-to-cart, at order-time, nor at shipment-time,
since no vendor-facing shipment action exists yet — `VendorProduct.quantity` is validated but
never decremented; two concurrent checkouts against the same limited quantity are not
resolved atomically), vendor-identity display inside Bagisto's own storefront cart/checkout UI
(only the admin order view, the vendor portal's own order pages, and the Marketplace's own
product detail page show it), `Virtual`/`Downloadable`/`Bundle`/`Booking`/`Grouped`
vendor-offer cart/order support (only `Simple` was extended), end-to-end Paymob/live-payment
marketplace order testing, final security/regression/performance E2E testing, refunds/RMA
integration with vendor ownership, a dedicated `VendorOrderPolicy` class (reused existing
`VendorPolicy::view` instead — no new permission tier was needed for read-only visibility).

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

Phase 10 resolved: vendor-aware order creation (zero-migration — `order_items.additional`
already existed and `OrderItemResource` already copied `cart_items.additional` through),
purchase-time vendor snapshot (name/SKU/price, immune to later `VendorProduct` changes),
multi-vendor single-order preservation (no splitting), checkout-time re-validation of
vendor/offer eligibility reusing the existing `Cart::validateItems()` extension point,
read-only admin order-item marketplace panel via `view_render_event` (no core file edited).

Phase 11 resolved: vendor-scoped order visibility (list + detail) as a filtered,
query-level-authorized view of the canonical `orders`/`order_items` (zero new tables),
order-item-level ownership derived solely from the Phase 10 historical snapshot, verified
multi-vendor isolation on a real shared order, a dedicated read-only admin cross-vendor
oversight page, and an explicit, justified decision to defer vendor-initiated shipment
creation (core shipment architecture is coupled to platform-level inventory sources, not
vendor-owned) rather than fake or duplicate it.

Phase 12A resolved: the reusable Marketplace financial ledger foundation with immutable transaction headers and debit/credit entries (two new tables), fixed account codes, EGP-only monetary handling with DECIMAL(18,4) storage and integer minor-unit arithmetic, atomic posting, and race-safe idempotency. Corrections are explicitly modeled as reversing transactions rather than mutations or deletes. The ledger is intentionally a foundation only: order financialization, company fees, commissions, payments, B2B credit integration, refunds, cancellations, settlements, vendor platform fees, and guarantee workflows remain deferred to later phases.
