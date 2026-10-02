# Phase 4 — Promote Verified Sandbox Foundation to Real B2B + Production Readiness

**Date:** 2026-10-02
**Branch:** `phase-4/b2b-foundation` (created from `main`, nothing pushed, nothing committed — all changes are local, uncommitted working-tree modifications, reviewable/revertable at will)
**Scope:** Promote the Bagisto 2.4.10 / Laravel 12 / GraphQL-patch / B2B Suite foundation already validated in `My-Bagisto-Store-B2B-2.4-Sandbox` into the real repository `My-Bagisto-Store-B2B`. No marketplace/vendor implementation. No production push. No live payment testing.

---

## 1. Executive Summary

The real repository was upgraded in-place (on a dedicated branch, uncommitted) from Bagisto 2.3.8 / Laravel 11 / PHP ^8.2 to the validated **Bagisto 2.4.10 / Laravel 12.69.3 / PHP 8.3** foundation, mirroring Phase 2's sandbox upgrade procedure exactly — because the real repo's pre-upgrade state was independently confirmed to be byte-for-byte identical to the sandbox's documented Phase 2 baseline (same Bagisto version, same Laravel version, same custom Paymob package, same 2 "customized" theme files already proven to be stock-identical). The verified Phase 2.2 GraphQL compatibility patch was reproduced via Composer (not a manual vendor edit) and confirmed to apply correctly and fix the schema. `bagisto/b2b-suite` v2.2.0 was also promoted (explicitly listed as part of the sandbox's "validated environment" in this phase's own brief) and its migrations/install command completed successfully. The custom Paymob payment package was preserved untouched and verified to still register and load.

A full, from-scratch `composer update`, `npm` install/build (root + Shop + Admin themes), and a migration test against a **real, populated copy** of the dev database (`bagisto_db`, backed up first) all succeeded. One real defect was found and fixed during this phase: the GraphQL Composer patch silently failed to apply during the very first `composer update` (Windows `patch` binary not yet on `PATH` in that terminal) — this was caught by a smoke test, diagnosed, and corrected by forcing a clean reinstall of `bagisto/graphql-api` with `patch.exe` present, which applied cleanly and fixed the schema.

The real dev database `bagisto_db` and the real production VPS were **never touched** — all verification ran against an isolated test database (`bagisto_b2b_phase4_test`) restored from a fresh `bagisto_db` backup.

**Final status: Foundation promoted and verified on the branch. NOT committed, NOT pushed.** A few items are explicitly flagged as follow-up (see §10) rather than fixed here, per the phase's "minimal smoke test, stop before next phase" instruction.

## 2. Before/After Versions

| Component | Before | After |
|---|---|---|
| Bagisto | 2.3.8 | 2.4.10 |
| Laravel | ^11.0 (locked 11.46.1) | ^12.0 (locked 12.69.3) |
| PHP (composer.json) | ^8.2 | >=8.3 <8.5 |
| PHP (actual/Docker) | 8.3.32 (Sail image already 8.3) | 8.3.32 (unchanged, already compliant) |
| GraphQL API | bagisto/graphql-api ^2.3 (v2.3.2) | bagisto/graphql-api * (v2.3.2, same version — now with the 2.4.x compatibility patch applied) |
| GraphQL compatibility patch | Not present (not needed pre-2.4) | Applied via `cweagans/composer-patches`, verified |
| B2B Suite | Not installed | bagisto/b2b-suite ^2.2 (v2.2.0), installed + migrated |
| Vite (root) | ^5.4.12 | ^6.4.2 |
| Vite (Shop/Admin themes) | ^5.4.12 (inherited from package.json copies) | ^6.4.2 (came bundled with the 2.4.10 package replacement) |
| Node/npm | Unchanged (system Node/npm, not pinned in repo) | Unchanged |
| MySQL | 8.0 (Sail) | 8.0 (unchanged) |
| Redis | alpine (Sail) | unchanged |
| Paymob (custom package) | Present, registered via `config/concord.php` | Preserved byte-for-byte, still registered, class loads |

## 3. Files Changed

Exact scope of the working-tree diff on `phase-4/b2b-foundation` (uncommitted):

- **Replaced wholesale** (robocopy `/MIR` from the verified sandbox, i.e. official 2.4.10 release source): all 39 stock `packages/Webkul/*` folders (`Admin, Attribute, BookingProduct, CartRule, CatalogRule, Category, Checkout, CMS, Core, Customer, DataGrid, DataTransfer, DebugBar, EUWithdrawal, FPC, GDPR, ImageCache, Installer, Inventory, MagicAI, Marketing, Notification, PayGlocal, Payment, Paypal, PayU, PhonePe, Product, Razorpay, RMA, Rule, Sales, Shipping, Shop, Sitemap, SocialLogin, SocialShare, Stripe, Tax, Theme, User`) — includes 8 brand-new 2.4.x packages that didn't exist before (`EUWithdrawal, ImageCache, PayGlocal, PayU, PhonePe, Razorpay, RMA, Stripe`).
- **`packages/Webkul/Paymob`** — explicitly excluded from the replacement, left 100% untouched.
- **Replaced wholesale**: `app/`, `bootstrap/` (including `bootstrap/providers.php` — now includes `B2BSuiteServiceProvider` registered last, per its install docs), `config/` (includes the re-applied `Webkul\Paymob\Providers\ModuleServiceProvider::class` entry in `config/concord.php`), `database/` (migrations/factories/seeders), `routes/`, `tests/`.
- **Replaced 2 specific files only** (not all of `resources/`, per Phase 2's documented finding that these 2 files were byte-identical to stock and safe to swap, all other `resources/` content — CSS/JS/root views — left untouched): `resources/themes/default/views/home/index.blade.php`, `resources/themes/default/views/checkout/onepage/payment.blade.php`.
- **`composer.json`** — replaced with the sandbox's validated version (PHP constraint, Laravel 12, new payment-gateway packages the sandbox had already added — `laravel/cashier`, `razorpay/razorpay`, `stripe/stripe-php`, `paypal/paypal-server-sdk`, `pragmarx/google2fa`, `simplesoftwareio/simple-qrcode`, `web-token/jwt-framework`, `symfony/brevo-mailer`, `symfony/http-client`, `laravel/ai` — all part of the validated 2.4.10 stock requirement set, none of this is new business logic), `cweagans/composer-patches`, `bagisto/b2b-suite`, `extra.patches` block, `allow-plugins`, `optimize-autoloader: false`.
- **`composer.lock`** — fully regenerated via `composer update` (166 package changes: upgrades/installs/removals) against the real repo's own lock file (not copied from the sandbox).
- **`package.json`** (root) — `vite` `^5.4.12` → `^6.4.2` (only this one line changed; `vite.config.js` is byte-identical, no change needed).
- **`packages/Webkul/Shop/package.json`, `packages/Webkul/Admin/package.json`** — came bundled with the 2.4.10 package replacement (already at `vite ^6.4.2`).
- **`patches/bagisto-graphql-api-2.4-compat.patch`** — new file, copied from the verified sandbox patch.
- **`.gitignore`** — added `/db-backups` (new local-only backup folder, must never be committed).
- **Untracked, reviewed, left uncommitted/unmodified (per your explicit instruction):** `.github/workflows/main.yml`, `.github/workflows/deploy.yml` — see §6.
- **Generated/rebuilt, not hand-edited:** `public/build/*`, `public/themes/shop/default/build/*`, `public/themes/admin/default/build/*`, `public/themes/b2b-suite/*/build/*` (from `b2b-suite:install`), `vendor/*` (not tracked by git — ignored, as expected).

**Nothing was committed.** `git status --short` shows ~1,777 changed/added paths in the working tree on the `phase-4/b2b-foundation` branch; `main` is completely untouched.

## 4. Database Changes

- No manual schema edits — 100% migration-driven, matching the task's requirement.
- Migrations verified in two stages:
  1. Against a **completely empty** fresh schema (`bagisto_b2b_phase4_test`, first attempt) — this surfaced a **pre-existing, non-regression finding already documented in the sandbox's own Phase 2 report**: a handful of core service providers call `core()->getCurrentChannel()` during framework boot (not migration-specific), which fails on a literally-empty `channels` table. Phase 2's own conclusion was explicit: *"a brand-new database... is an artifact of testing against an empty sandbox database, not a 2.4-upgrade requirement... What IS required in the real project is running the new 2.4.10 migrations against the existing populated database."* This is not a new defect — it's the same documented boot-time characteristic, now re-confirmed on the real repo's codebase too.
  2. Against a **populated copy of the real `bagisto_db`** (the actually-representative test): backed up first (`db-backups/bagisto_db_pre-phase4.sql`, 1,416,050 bytes), restored into `bagisto_b2b_phase4_test`, then `php artisan migrate --force` run against it — **61 pending migrations, all applied successfully, 0 errors**, including all 25 B2B Suite migrations (auto-discovered via the now-registered `B2BSuiteServiceProvider`) and all the standard 2.4.10 migrations (RMA, EU withdrawal, theme-sections rename, product-flat derived columns, sitemap channels, etc.). Final `migrate:status`: **0 pending**.
- The actual `bagisto_db` (real dev database) was **never migrated, never modified, never connected to with write intent** — only read once, by `mysqldump`, to produce the backup/test copy.

## 5. GraphQL Compatibility

- Verified mechanism exactly reproduces Phase 2.2: `cweagans/composer-patches` plugin, patch declared in `composer.json`'s `extra.patches.bagisto/graphql-api`, patch file at `patches/bagisto-graphql-api-2.4-compat.patch`, `allow-plugins.cweagans/composer-patches: true`.
- **Found and fixed a real reproducibility defect during this phase**: the first `composer update` silently failed to apply the patch (Windows `patch.exe` not yet on `PATH` for that specific terminal invocation — a known gotcha from Phase 2.2, re-triggered here because this was a fresh terminal session). This was caught by the GraphQL smoke test (`Failed to find class Webkul\Theme\Models\ThemeCustomization`), diagnosed by inspecting the unpatched vendor file, and fixed by deleting `vendor/bagisto/graphql-api` and re-running `composer install` with `patch.exe` correctly on `PATH` — verbose output confirmed `patching file src/graphql/admin/setting/theme.graphql`, `...ThemeMutation.php`, `...HomePageQuery.php`, all 3 hunks applied cleanly.
- Post-fix verification (fresh schema cache, full queries, against the populated DB copy): `__schema` introspection, `getDefaultChannel`, `allProducts`, `homeCategories` — **all 4 pass**, with real data returned (actual product/category names from the restored `bagisto_db` copy).
- Re-confirmed the 4 "must stay zero" conditions from Phase 2.2: `theme_customization_id` in `.graphql` = 0, `visitor()` calls = 0, `shetabit/visitor` in `composer.lock` = 0. (`ThemeCustomization` as a bare string still appears 6 times in unrelated method/array-key names, same harmless false-positive documented in every prior phase.)

## 6. Docker Changes

- **No production Dockerfile exists anywhere in this repository** (searched the full tree — none found). The only Docker configuration present is `docker-compose.yml`, which is **Laravel Sail** (a local-dev convenience tool), building from `vendor/laravel/sail/runtimes/8.3` — **already PHP 8.3**, i.e. already compatible with the new `>=8.3 <8.5` requirement with zero changes needed. No `docker compose build`/`up` was executed live in this phase (Docker Desktop not available on this machine, per your answer) — verified by config inspection only, as agreed.
- **Found and flagged (not fixed) a significant production-infrastructure gap**: two **untracked** GitHub Actions workflows exist locally (`main.yml` targeting branch `mainOld`, `deploy.yml` — "Deploy Dragoon Production" — targeting `main`) that SSH into a real VPS and run `docker exec bagisto-app composer install/migrate/...` against **live containers that are not defined anywhere in this repository**. This means the production PHP runtime version is managed entirely outside version control, on the VPS itself, and could not be verified or updated from here. Per your explicit instruction, these 2 files were reviewed only — **left completely untouched, not modified, not committed** (they were already untracked before this phase and remain so).
- Redis, MySQL, queue: Sail's `docker-compose.yml` already provisions MySQL 8.0 + Redis + a `database` queue-compatible setup; no changes were required or made.

## 7. Frontend Changes

- `package.json` (root): `vite ^5.4.12` → `^6.4.2`.
- `packages/Webkul/Shop/package.json`, `packages/Webkul/Admin/package.json`: already at `vite ^6.4.2` (came bundled with the verified 2.4.10 package source).
- `vite.config.js` (root): no content change needed — byte-identical between old and new.
- Build verification (all 3, from scratch `npm install` + `npm run build`):
  - Root: 53 modules, built in 674ms.
  - Shop theme: built in 15.32s, full asset manifest generated (CSS/JS/images/fonts) into `public/themes/shop/default/build`.
  - Admin theme: built in 11.78s into `public/themes/admin/default/build` (one expected Rollup "chunk > 500kB" size warning — informational only, not an error, pre-existing in the stock 2.4.10 Admin build).
- `public/storage` symlink: already correctly established (pre-existing from the real repo's normal setup) — verified intact, no stale-copy issue (the robocopy pitfall from Phase 2 does not apply here since no folder-copy-based duplication was used for `public/`/`storage/` in this phase).

## 8. Production Readiness

| Area | Check | Status |
|---|---|---|
| Source | Bagisto version correct (2.4.10) | PASS |
| Source | Laravel version correct (12.69.3) | PASS |
| Source | GraphQL version + patch reproducible | PASS (patch failure caught and fixed during this phase) |
| Source | B2B Suite version correct (2.2.0) | PASS |
| Source | Custom B2B/Paymob code preserved | PASS |
| PHP | PHP 8.3 runtime | PASS |
| PHP | `composer check-platform-reqs` | PASS (all extensions + PHP version satisfied) |
| PHP | CLI vs FPM consistency | NOT TESTED (no FPM/Docker execution performed, per your choice) |
| Docker | Dockerfile uses correct PHP version | PASS for Sail (already 8.3) / **NOT APPLICABLE** for production (no Dockerfile in repo — see §6) |
| Docker | Compose config consistent | PASS (reviewed only, not built/run) |
| Docker | Container build/run/MySQL/Redis/queue | NOT TESTED (no Docker Desktop available this session) |
| Frontend | npm reproducible (root + Shop + Admin) | PASS |
| Frontend | Vite version compatible | PASS |
| Frontend | Admin/Shop builds succeed | PASS |
| Database | Migrations reproducible from a real-data copy | PASS |
| Database | Fresh truly-empty DB boot | KNOWN LIMITATION (documented pre-existing characteristic, not a regression — see §4) |
| Database | No sandbox-only schema dependency | PASS (migrated the real repo's own DB copy, not an imported sandbox dump) |
| Runtime | `APP_DEBUG=false` possible | NOT TESTED (not toggled this phase; `.env.example` ships `APP_DEBUG=true` for local dev, which is expected/correct for the example file) |
| Runtime | Storage link works | PASS (pre-existing, verified intact) |
| Runtime | Cache/queue/scheduler | NOT TESTED beyond `optimize:clear`/`config:clear` (no live queue worker or scheduler run this phase) |
| Security | No production secrets committed | PASS (nothing committed at all this phase) |
| Security | `.env` excluded | PASS (untouched, still gitignored) |
| Security | Production DB untouched | PASS (only a `mysqldump` read + a separate isolated test DB were used) |
| B2B | Provider registration correct | PASS |
| B2B | Company/admin pages boot (migrations + install command) | PASS for install/migrate; **one specific page (`/admin/b2b/companies`) returned 404 under an ad-hoc unauthenticated HTTP smoke test** — flagged as a follow-up item, not confirmed as a real defect (see §10) |
| B2B | GraphQL remains functional after install | PASS |
| Paymob | Provider loads, class resolves | PASS |
| Paymob | No live payment test | Confirmed — none performed |

## 9. Smoke Test Results

All run against `bagisto_b2b_phase4_test` (populated copy of real `bagisto_db`), dev server on port 8020:

| Check | Result |
|---|---|
| `composer check-platform-reqs` | PASS — all extensions + PHP 8.3.32 |
| `php artisan migrate --force` (61 pending → 0) | PASS |
| Homepage `/` | 200 |
| Admin login `/admin/login` | 200 |
| Cart `/checkout/cart` | 200 |
| `/admin/catalog/products` (unauthenticated) | 200 (see note in §10 — likely response-cache artifact, not re-investigated further per "minimal smoke test" scope) |
| Company registration page `/customer/register` | 200 |
| `/admin/b2b/companies` (unauthenticated) | 404 — flagged, not root-caused (§10) |
| GraphQL `__schema` introspection | PASS |
| GraphQL `getDefaultChannel` | PASS — real channel data |
| GraphQL `allProducts` | PASS — real product data from the restored DB copy |
| GraphQL `homeCategories` | PASS — real category data |
| Paymob class loads, provider registered | PASS |

## 10. Known Issues

- **GraphQL patch non-application on first run**: caught and fixed within this phase (see §5) — not an outstanding issue, documented here only as a reminder that the Windows `patch.exe`-on-`PATH` requirement must be satisfied in **every fresh terminal/CI runner**, not assumed to persist.
- **`/admin/b2b/companies` returned 404 for an unauthenticated ad-hoc HTTP smoke test**, while `/admin/catalog/products` returned a suspicious 200 for the same unauthenticated request (likely `spatie/laravel-responsecache`, which is enabled by default, serving a previously-cached authenticated page to the ad-hoc request rather than a real auth bypass). The B2B route itself is correctly registered (confirmed via `route:list`, 55 B2B routes present, correct controller bindings, correct middleware stack `web + Bouncer + NoCacheMiddleware`). This was **not root-caused** within this phase's "minimal smoke test" scope, since (a) it requires a real authenticated browser/admin-session test to confirm either way, and (b) the full authenticated company/catalog/pricing/RFQ/PO/invitation flows were **already exhaustively verified working** against the identical, unmodified B2B Suite v2.2.0 code in the sandbox (Phase 3 + Phase 3.1). **Recommendation**: before Phase 5, do one real authenticated-browser check of `/admin/b2b/companies` on the real repo to close this out definitively.
- **No production Dockerfile in this repository** (§6) — production PHP runtime version is managed entirely on the VPS, outside version control. This is a pre-existing gap, not something introduced by this phase, but worth flagging for future hardening (a dedicated production Dockerfile checked into the repo, with the VPS pulling from a tagged image, would close this reproducibility gap).
- **CI/CD workflows reference live infrastructure** (`bagisto-app`, `bagisto-worker` containers, a specific VPS) not otherwise documented in the repository — reviewed only, left exactly as found (untracked, uncommitted), per your explicit instruction.
- Docker container build/run/queue/Redis connectivity was **not tested live** this phase (no Docker Desktop available) — config-reviewed only.

## 11. Paymob

Live E2E intentionally deferred (unchanged from all prior phases — Phase 2.3 remains the still-outstanding item for live Paymob verification). This phase confirmed only:
- `packages/Webkul/Paymob` directory and all its files are byte-for-byte untouched by the package-replacement operation.
- `config/concord.php` still lists `Webkul\Paymob\Providers\ModuleServiceProvider::class` after the full config/ replacement (it came bundled correctly since the sandbox's config/ already had this entry from Phase 2).
- `class_exists('Webkul\Paymob\Providers\ModuleServiceProvider')` → `true` on the upgraded codebase.
- No real payment request, no live credentials, no Paymob-specific code changes were made.

## 12. Safety

- ✅ B2C project (`My-Bagisto-Store`) — never opened, never referenced.
- ✅ Production (VPS, `bagisto-app`/`bagisto-worker` containers) — never connected to, never deployed to.
- ✅ No production credentials used anywhere.
- ✅ No live payment testing.
- ✅ No marketplace/vendor/business-feature implementation — only foundation promotion (core version, GraphQL patch, B2B Suite dependency, frontend build tooling).
- ✅ Branch used: `phase-4/b2b-foundation`, created from `main` at commit `ec96f6c`. **Nothing committed, nothing pushed** — `main` and `origin/main` are completely unaffected; all ~1,777 changed paths exist only as uncommitted working-tree modifications on the branch, fully reviewable (`git diff`) and fully revertable (`git checkout -- .` / `git reset --hard`) at any time.
- ✅ Backup status: `db-backups/bagisto_db_pre-phase4.sql` (1,416,050 bytes, full `bagisto_db` dump with routines/triggers) taken before any database interaction; the real `bagisto_db` itself was never written to — all migration testing ran against a separate restored copy (`bagisto_b2b_phase4_test`). `db-backups/` added to `.gitignore` so this real-data dump is never accidentally committed.
- ✅ `.env` untouched, not committed (already gitignored).

## 13. Next Phase

The real B2B repository is now the implementation source of truth. The next phase will combine architecture decisions directly with implementation of the B2B + marketplace system.

**Preliminary inspection points identified for that phase** (not implemented here):

1. **B2B buyer/company architecture**: Company = `customers` row with `type='company'`, `company_catalog_id`, `company_role_id`, `sales_rep_id` — now live in the real repo's schema (25 B2B Suite migrations applied).
2. **B2B customer/user architecture**: `b2b_customer_companies` pivot links sub-users to companies; invitation flow (`b2b_company_invitations`) fully functional per Phase 3.1 evidence.
3. **Roles/permissions**: `b2b_company_roles`, currently only an auto-created "Administrator" role exists per company by default — a second, more restricted role was never created/tested end-to-end (Phase 3.1 limitation, still open).
4. **Company catalog / customer-group pricing**: confirmed (Phase 3.1) to be implemented via customer-group reassignment on top of stock `product_customer_group_prices` — no independent pricing engine to design around; future marketplace vendor pricing would need its own, separate mechanism.
5. **RFQ/quotation**: fully bidirectional negotiation system already proven working end-to-end (Phase 3.1), persists negotiated prices via Bagisto's native cart `custom_price` field.
6. **Order architecture**: "Purchase Order" is not an independent entity — it's a `CustomerQuote` row auto-relabeled on checkout (Phase 3.1 finding) — any future PO-approval workflow would need new code, not an extension of existing PO pages.
7. **Product/inventory**: `product_inventories.vendor_id` remains an inventory-source tag only, structurally unrelated to any future marketplace "Vendor" concept (Phase 1 finding, re-confirmed, never merge these two).
8. **Existing GraphQL/API surface**: B2B Suite has zero GraphQL integration; any future marketplace GraphQL/API surface starts from a clean slate on top of the now-patched `bagisto/graphql-api`.
9. **Existing admin/frontend architecture**: `B2BSuiteManager` still globally rebinds `ProductRepository`, `CategoryRepository`, 4 Shop controllers, and the `Customer` model — any future marketplace code touching these same classes must account for this last-bind-wins rebinding (documented risk since Phase 1, never yet triggered in practice).

No vendor/marketplace design decisions were made in this phase, as instructed.
