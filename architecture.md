# SaaS M-Pesa Integration Platform - Architecture Brief (v2.0)

## 1. Core Objectives
* **Frictionless Payments:** Allow Kenyan SMBs and corporate retail chains to initiate M-Pesa STK Pushes directly to customers.
* **Multi-Branch Support:** Scale from single-attendant kiosks to nationwide supermarkets with hundreds of branches.
* **High Isolation Security (Smart Hybrid):** Isolate both application runtimes and databases completely using a per-tenant Docker container model.
* **Isolated Management:** Enable dedicated SSH/secure terminal access per tenant container for hyper-localized debugging, log auditing, and environment management.
* **Extensibility:** Built modularly to easily integrate future features (Inventory, Gym Billing, Card Gateways).

---

## 2. Technical Stack & Infrastructure
* **Backend Framework:** Laravel 11+ (PHP 8.3+)
* **Frontend Framework:** Vue.js 3 or React (SPA via Inertia.js or decoupled API layer)
* **Cross-Platform Wrapper:** CapacitorJS (Compiles the unified web build into native Android/iOS and Desktop containers)
* **Databases:** PostgreSQL or MySQL (1 Global Landlord Database + 1 Isolated DB Instance *inside* each Tenant Container)
* **Host Engine:** Docker & Docker Compose running on a single robust VPS (horizontally scalable to multi-node clusters later)
* **Web Server / Edge Proxy:** Central Nginx or Caddy server managing dynamic SSL proxy rules (Cloudflare for SaaS integration for custom vanity domains).

---

## 3. Tenancy, Domain & Routing Architecture
The platform isolates customer spaces cleanly by running each company within its own Docker network stack while sharing a single physical server infrastructure.

### 3.1 Domain Layout Options
* **Subdomains (Default):** Assigned instantly at onboarding (`tenant-name.yoursaas.co.ke`).
* **Custom Vanity Domains (Tiered Feature):** Users link external domains (e.g., `pos.naivas.co.ke`) via CNAME records. 

### 3.2 System Layout Map
```
[ Incoming HTTPS Request ]
                    (Subdomain or Custom Vanity Domain)
                                   │
                                   ▼
                   [ Central Nginx / Caddy Proxy ]
                                   │
     ┌─────────────────────────────┼─────────────────────────────┐
     │ (Proxy Pass to Port 8001)   │ (Proxy Pass to Port 8002)   │
     ▼                             ▼                             ▼
┌──────────────────────────────┐ ┌──────────────────────────────┐ ┌─────────────────────┐
│ CONTAINER A: NAIVAS STACK    │ │ CONTAINER B: QUICKMART STACK │ │ LANDLORD ENGINE     │
│ ├─ Isolated Laravel App Core │ │ ├─ Isolated Laravel App Core │ │                     │
│ ├─ Dedicated DB (db_a)     │ │ ├─ Dedicated DB (db_b)     │ │ ├─ System Control   │
│ └─ Isolated Local SSH (2201) │ │ └─ Isolated Local SSH (2202) │ │ └─ Global Billing DB│
└──────────────────────────────┘ └──────────────────────────────┘ └─────────────────────┘
```

---

## 4. Landlord Control Plane (Centralized Orchestration)
The Landlord service lives outside the tenant container network. It holds the license terms, system settings, and routing instructions.

### 4.1 Landlord Core Schema
* **`tenants`:** `id`, `uuid`, `company_name`, `subdomain`, `custom_domain_nullable`, `container_port`, `ssh_port`, `status` (`active`, `suspended`).
* **`licenses`:** `id`, `tenant_id`, `plan_tier` (`starter`, `growth`, `enterprise`), `starts_at`, `expires_at`, `status` (`trialing`, `active`, `past_due`, `expired`).

---

## 5. Tenant Database Schema (Isolated Inside Container)
* **`branches`:** `id`, `name`, `location`, `mpesa_shortcode_override_nullable`.
* **`users`:** `id`, `branch_id`, `name`, `email`, `password`, `role` (`admin`, `manager`, `teller`).
* **`contacts`:** `id`, `name`, `phone_number`.
* **`transactions`:** `id`, `branch_id`, `user_id`, `contact_id`, `amount`, `payment_method`, `status` (`pending`, `success`, `failed`), `mpesa_checkout_request_id`, `mpesa_merchant_request_id`.

---

## 6. Subscription Enforcement & Global Deployments
* **Monthly License Check:** Every 24 hours, a cron job on the Landlord control plane audits the `licenses` table. If a tenant's license state turns `expired`, an API trigger forces their isolated container to toggle an `APP_MAINTENANCE=true` state, restricting backend access until payment is completed.
* **Global Core Updates:** Feature code updates are maintained inside a master Git repository. A single CI/CD script builds a master base Docker image and pushes the tag update to all live container environments simultaneously, executing automated migrations sequentially per tenant container.
* **Modular Module Flagging:** Code updates contain the entire suite of services (Inventory, Gym Billing, etc.). Access is locked natively via application Gates that check the local environment payload derived from the Landlord license plan tier.

## 7. Public Customer Portals (Self-Service)
* **Route Isolation:** Runtimes differentiate between staff dashboards and public customer interfaces using distinct URL route paths or dedicated portal subdomains (e.g., `tenant.platform.co.ke/portal`).
* **Self-Initiated STK Push:** Customers can input their unique identifier (Member ID, House Number, or Phone) to fetch outstanding balances and fire an automated M-Pesa STK Push directly to their handsets.
* **Automated Renewal Logic:** Upon successful webhook confirmation from Safaricom, a background job localized to the tenant's container instantly updates the customer's payment history and recalculates membership or rent validity dates (`expires_at`), removing manual administrative bottlenecks.
