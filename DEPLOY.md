# Deploying Aero PO

The application has automated workflow and security tests. Production infrastructure, credentials, mail delivery and the operational DigiMaint handover must be configured and checked in the deployment environment.

## Environment and hosting

Point the web server at `public/`. Configure the tenant base domain, wildcard DNS and TLS for `{slug}.yourdomain.com`. Force HTTPS at the trusted reverse proxy or web server.

```dotenv
APP_NAME="Aero PO"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_DOMAIN=yourdomain.com
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_FROM_ADDRESS=procurement@yourdomain.com
```

Use production database and mail credentials. Host-only cookies keep each tenant login separate. Set the application key once and preserve it across releases; it also protects session data and encrypted temporary credentials.

## Release commands

Back up the database and private documents before migration. From the release directory:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

The procurement migration adds structured commercial, receiving, handover and audit records without inventing missing historical facts. Existing orders need their references, commercial approvals and actual DigiMaint evidence reconciled before they can satisfy the new gates.

The repository already includes queue and cache table migrations. A public storage link is unnecessary for procurement documents.

## Queue and delivery

Run a managed worker continuously, for example under Supervisor or systemd:

```bash
php artisan queue:work --tries=1 --timeout=120
```

RFQ and PO delivery jobs are dispatched after database commit and explicitly restore their tenant context. PO status changes to sent only after the mail transport returns successfully. Approval and shipment notifications are queued too. Configure process restart and failed-job monitoring, and restart workers on each release.

Review failures with `php artisan queue:failed`. A connection failure can have an ambiguous delivery outcome. Check the provider and vendor before retrying an RFQ or reissuing a PO. Use the application delivery controls; a failed delivery does not authorize bypassing CFO approval. The log mailer is suitable for local development only.

## Private records and backups

Documents and generated PDFs use the `private` disk, currently `storage/app/private`, and authenticated tenant-scoped download endpoints. Include this directory and the database in backups; keep uploaded files outside the web root. If running multiple app servers, configure shared private storage and include the required storage driver. Verify restores and monitor queue failures, application errors and storage capacity.

## Company onboarding

Do not run the demonstration seeder in production. Provision the first administrator using:

```bash
php artisan tenant:create aero "Aero Contractors" admin@your-company.example
```

Change the generated password after first login. The administrator creates users, approves the appropriate supplier/carrier records and assigns approvers. Role order follows the documented process. Both CIA and post-receipt net-terms invoices currently use Cost Control, TLM, TD, Audit, Payables and Treasury; confirm this operational choice with the process owner. TLMs set their own SLA and KPI targets in Settings.

## DigiMaint and acceptance

Use the [manual handover procedure](docs/DIGIMAINT_MANUAL_PROCESS.md). DigiMaint remains authoritative. Each stock check, receipt or stock issue needs a reference, actual action time, quantity, private evidence and independent Store Supervisor verification. Closure requires verified receipts or issues, plus the applicable serviceable-label and Cardex records. Closing an order does not post stock in DigiMaint.

Before operational use, exercise one CIA order, one net-terms order, a return for correction, a freight shipment and a partial delivery with the nominated staff. Confirm actual mail delivery and that staff can complete and independently verify the DigiMaint records. Net-terms payment follows receipt and can remain open after procurement closure; use the finance queue to finish it. Automated tests do not verify the company's DigiMaint installation or its physical receiving procedures.