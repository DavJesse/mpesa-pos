# M-Pesa SaaS - Landlord Control Plane

This directory contains the **Landlord Control Plane** for the M-Pesa SaaS integration platform. 

Unlike the tenant applications (which run in isolated Docker containers with separate databases), this is a central, singular Laravel application responsible for global orchestration.

## Responsibilities
* **Tenant Management:** Tracks all onboarded companies (`tenants` table) and their custom routing logic (subdomains, vanity domains, container ports).
* **License & Subscription Enforcement:** Tracks the billing status (`licenses` table) of every tenant.
* **Internal APIs:** Provides secure endpoints (e.g., `GET /api/internal/verify-license/`) that the isolated tenant containers query to determine if they should allow POS operations or lock the user out due to missed payments.
* **Scheduled Audits:** Runs a daily background task to automatically flag expired subscriptions.

---

## Local Development Setup

To test the Landlord locally alongside your tenant Docker containers, the quickest method is to use PHP's built-in server with an SQLite database.

### 1. Install Dependencies & Environment
```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Database Configuration
Open the `.env` file and configure it to use SQLite by commenting out/removing the MySQL variables:
```env
DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=
```
*(Note: Be sure `DB_DATABASE` is completely removed so Laravel defaults to `database/database.sqlite`)*

Create the SQLite file and migrate the database:
```bash
touch database/database.sqlite
php artisan migrate
```

### 3. Start the Server
Start the built-in PHP server. It will run on `http://127.0.0.1:8000` by default.
```bash
php artisan serve
```

---

## Connecting Tenants to the Landlord

Because the tenant applications run inside Docker, they cannot resolve `localhost` directly to your host machine. 

To allow a local tenant container to communicate with your local Landlord server, add the following to your `tenant-template/.env`:

```env
# Point to the Docker Host IP
LANDLORD_URL=http://host.docker.internal:8000/api/internal/verify-license/

# The UUID matching the tenant record in the Landlord DB
TENANT_UUID=your-test-tenant-uuid
```

---

## Key Artisan Commands

### `php artisan licenses:audit`
This command audits all licenses in the database. If a license's `expires_at` date has passed, it updates the status to `expired`.
* **Production:** This is scheduled to run daily at midnight via Laravel's Task Scheduler (invoked by the system cron).
* **Local:** You can run it manually at any time to test the expiration logic.
