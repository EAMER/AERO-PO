# Deploying Aero PO System

Checklist for moving this off `php artisan serve` onto a real server. Nothing
here has been run or tested - treat it as a starting checklist, not a verified
runbook.

## 1. Environment

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_DOMAIN=yourdomain.com          # tenants become {slug}.yourdomain.com
SESSION_DOMAIN=.yourdomain.com     # leading dot: cookie valid across all tenant subdomains... 
                                    # ...or leave unset for host-only cookies (one login per tenant, more isolated)
```
Decide SESSION_DOMAIN deliberately: a shared `.yourdomain.com` cookie means a
browser logged into `aero.yourdomain.com` carries a session cookie to
`demo.yourdomain.com` too (though EnsureUserBelongsToTenant still blocks
cross-tenant use). Host-only (omit SESSION_DOMAIN) is more isolated but means
logging into each tenant separately even in the same browser.

## 2. DNS

A wildcard record so every tenant subdomain resolves:
```
*.yourdomain.com   A       <server IP>
yourdomain.com     A       <server IP>
```

## 3. TLS

Wildcard cert (`*.yourdomain.com`) via Let's Encrypt (DNS-01 challenge,
since wildcard certs can't use HTTP-01) or your host's managed cert. Force
HTTPS in `bootstrap/app.php` or at the reverse proxy.

## 4. Mail

Currently `MAIL_MAILER=log` (the Laravel default) - notifications write to
`storage/logs/laravel.log` instead of sending anywhere. Before relying on
notifications for real:
```
MAIL_MAILER=smtp   # or ses, postmark, etc.
MAIL_HOST=...
MAIL_FROM_ADDRESS=noreply@yourdomain.com
```
Send a test: `php artisan tinker` -> `Notification::route('mail', 'you@x.com')->notify(new \App\Notifications\RequisitionDecided($po))`.

## 5. Queue (optional but recommended once mail is real)

Notifications currently send synchronously (the `Queueable` trait is used
but the classes don't implement `ShouldQueue`) - a slow mail provider will
slow down the request. To queue them: add `implements ShouldQueue` to each
notification class, then run a worker:
```
QUEUE_CONNECTION=database   # or redis
php artisan queue:table && php artisan migrate
php artisan queue:work --tries=3
```
Keep the worker running via Supervisor or systemd, not just a foreground
terminal.

## 6. Database

- Point `DB_*` at the production MySQL instance, not local Workbench.
- Back up before the first production migration.
- Set up regular backups (`mysqldump` on a cron, or your host's managed
  backup feature) - this holds every tenant's purchase orders and documents.

## 7. File storage

`storage/app/private` holds every uploaded document (quotations, invoices,
AWBs, etc.). On a single server this is fine as local disk, but:
- Include it in your backup routine.
- If you ever scale to multiple app servers, local disk won't be shared -
  move to S3 or similar and update the `'private'` disk driver in
  `config/filesystems.php`.

## 8. Deploy steps (each release)

```
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link   # only if you ever add a public disk
```
Skip `config:cache` in any environment where you still edit `.env` directly
without restarting - cached config ignores further `.env` changes until
`php artisan config:clear`.

## 9. First production login

Replace the seeded `*.test` accounts - they're fine for development but
shouldn't exist in production. Use `/users` (tenant admin only) to create
real accounts instead. Consider deleting or deactivating the seeded ones
per tenant once real users exist.

## 10. Still open before go-live

- DigiMaint integration (stock check + the close-out handover) is currently
  manual/fake - see the project plan doc's open decisions.
- KPIs for the TLM are undefined.
- No automated backup/monitoring/alerting has been set up - this document
  only lists what's needed, not a working implementation of any of it.
