# Aero PO System - Laravel foundation (Person 1)

Tenancy (subdomain + fail-closed global scope), roles, PO status enum with
transition map + audit log, sequential approvals, and tests. Drop-in bundle for a
fresh Laravel app.

## 1. Create the app (run locally)

```bash
composer create-project laravel/laravel aero-po
cd aero-po
composer require laravel/breeze --dev
php artisan breeze:install blade
```

## 2. Copy the bundle in

Copy `app/`, `config/`, `database/`, `tests/` from this bundle over the app
(this replaces `app/Models/User.php`). Then apply the two snippets by hand:

- `routes/web.snippet.php`   -> into `routes/web.php` (Breeze's `auth.php` require goes inside the domain group)
- `bootstrap-app.snippet.php` -> into `bootstrap/app.php`

## 3. Configure

`.env`:

```
APP_DOMAIN=localhost
SESSION_DOMAIN=null        # host-only cookies: one session per tenant subdomain
DB_CONNECTION=mysql
DB_DATABASE=aero_po
```

`database/seeders/DatabaseSeeder.php`: replace the body of `run()` with
`$this->call(FoundationSeeder::class);` and delete the default `User::factory()`
line (it has no tenant, so it is refused by design).

Also remove Breeze's public registration route/controller: tenant users are created by a tenant admin, not self-registered.

## 4. Run

```bash
php artisan migrate --seed
php artisan serve
```

Open http://aero.localhost:8000 (browsers resolve `*.localhost`) and log in as
`tlm@aero.test` / `password` (one seeded user per role, for tenants `aero` and `demo`).

```bash
php artisan test
```

## Rules for the other two developers

- Change `status` only via `WorkflowService::transition()`; react via `PurchaseOrderTransitioned`.
- Every new model uses `BelongsToTenant` and every new table has `tenant_id` (a test enforces the trait).
- Only Person 1 edits `PurchaseOrderStatus`, `Role`, and shared migrations.

## Known follow-ups

- Breeze's password-reset tokens are keyed by email; the same email in two tenants will collide. Scope them by tenant before go-live.
- `returned_by_approver` currently goes back to `quotes_in` or `pending_approval`; a CFO return could need its own path.
- Approval steps per tenant (e.g. always adding HAMO when `is_amo_request`) are chosen by the caller for now; move to `tenants.settings`.
- I could not run PHP here, so this bundle is untested. Run `php artisan test` first and send me any failures.
