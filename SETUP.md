# Local setup

## Requirements

PHP 8.3 or later with the extensions required by Composer, Composer, Node.js compatible with the locked Vite version, npm, and SQLite or MySQL. This implementation was tested on PHP 8.5 with SQLite in memory.

## Install

Run these commands from the application directory:

```bash
composer install
```

Copy `.env.example` to `.env` if it does not exist, then configure:

```dotenv
APP_NAME="Aero PO"
APP_ENV=local
APP_URL=http://localhost:8000
APP_DOMAIN=localhost
SESSION_DOMAIN=null
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

For SQLite, create `database/database.sqlite` if it does not exist. For MySQL, configure the database connection and create the empty database first. Keep an existing application's `.env`, key and database; do not regenerate its key.

On a new installation only:

```bash
php artisan key:generate
```

Install the schema and frontend assets:

```bash
php artisan migrate
npm ci
npm run build
```

## First administrator

Create a company and its administrator:

```bash
php artisan tenant:create aero "Aero Contractors" admin@your-company.example
```

The command prints a generated initial password. Share it privately and change it after first login. Open `http://aero.localhost:8000` after starting the server. Create other tenant users through Users, assign roles, approve vendors and carriers, and configure approver assignments and SLA/KPI targets through Settings.

For a disposable local demo, `php artisan db:seed` creates the `aero` and `demo` tenants, role accounts such as `tlm@aero.test` with password `password`, and sample unapproved vendors. Default-password seeding is refused outside local/testing. Use an empty demo database rather than seeding an operational installation.

## Run

Use separate terminals:

```bash
php artisan serve
```

```bash
php artisan queue:work --tries=1
```

For frontend development, run `npm run dev` in another terminal. The worker is required for RFQs, POs and notifications. The log mailer writes locally; configure SMTP or another supported mailer to deliver messages. Review delivery failures before retrying, because a mail provider can accept a message before a connection fails.

DigiMaint transfers are performed manually. Follow [the operating procedure](docs/DIGIMAINT_MANUAL_PROCESS.md); records require real references, evidence, explicit UTC offsets and a separate supervisor verification.

## Test and format

```bash
php vendor/phpunit/phpunit/phpunit
php vendor/bin/pint --dirty --format agent
npm run build
```

PHPUnit forces SQLite `:memory:` independently of the application's normal database. It must have access to `pdo_sqlite`. Vendor dispatch and handover tests use fakes; they do not send external messages or update DigiMaint.