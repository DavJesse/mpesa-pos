# M-Pesa SaaS POS - Tenant Template

This repository serves as the foundational template for all tenant applications in the M-Pesa SaaS POS network. When a new tenant registers on the Landlord control plane, a clone of this application is provisioned and deployed in an isolated container.

## Architecture

This application acts as a client to the central Landlord application. It does not handle subscription billing or global license management; instead, it queries the Landlord's internal verification API to check its operational status.

### Core Modules
*   **Mpesa**: Handles payment gateways, checkouts, and C2B/B2B transaction logging.
*   **Branches**: Supports multi-branch operations, isolating data at the point of sale.
*   **Contacts**: Customer and supplier management.
*   **System Settings**: Configuration for POS behavior, API keys, and gateways.

## Role-Based Access Control (RBAC) Spine

This application uses a dynamic, hierarchical RBAC system to manage user permissions, enforce branch data isolation, and handle manager overrides safely. 

### Roles and Hierarchy
Instead of hardcoded enums, roles are stored in the `roles` database table. Each role has a specific `level` (an integer) to prevent privilege escalation (a user cannot assign or create a role with a level higher than their own).
*   **Admin (`level: 100`)**: Full global access. Bypasses branch isolation.
*   **Manager (`level: 50`)**: Branch-specific oversight, reporting, and teller management.
*   **Teller (`level: 10`)**: Frontline staff, restricted to transaction processing for their assigned branch.

### Permissions
Fine-grained permissions (e.g., `void_transactions`, `configure_gateways`) are defined in `config/rbac.php` and stored in the `permissions` table. These are linked to roles via the `role_permissions` pivot table.

### Security & Overrides
*   **Deletion Protection**: System roles (Admin, Manager, Teller) are protected via an `is_system` flag and cannot be deleted.
*   **Manager Overrides**: The `users` table includes a `pos_pin` column, allowing managers to quickly authorize restricted actions (like voids or refunds) on a teller's screen without requiring a full logout.
*   **Branch Isolation**: Managers and Tellers are strictly scoped to their `branch_id`.

## Installation & Deployment

When provisioning this template for a new tenant, ensure the following commands are run to establish the schema and seed the system roles:

```bash
# Install required dependencies (Doctrine DBAL is required for schema modifications)
composer install

# Run all migrations
php artisan migrate

# Seed the database with the RBAC system roles and permissions
php artisan db:seed --class=RolePermissionSeeder
```

## Testing

The test suite utilizes an in-memory SQLite database. All RBAC and Landlord license verification middleware tests are included.

```bash
php artisan test
```
