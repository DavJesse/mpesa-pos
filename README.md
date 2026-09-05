# M-Pesa SaaS Integration Platform

A multi-tenant SaaS platform built for Kenyan SMBs and corporate retail chains to manage M-Pesa STK Pushes. 
This repository utilizes a **Unified Monorepo** architecture, housing both the central routing proxy and the isolated tenant environments.

## Architecture Overview

* **`proxy/`**: The Central Nginx Reverse Proxy. It binds to host ports 80/443 and dynamically routes incoming traffic to the correct isolated tenant container.
* **`tenant-template/`**: The boilerplate Docker Compose stack for a single isolated tenant. It includes a custom PHP 8.3-FPM + OpenSSH container, Nginx web server, PostgreSQL, and Redis.

## Getting Started (Local Development)

### 1. Start the Central Proxy
The proxy must be running to route traffic to your local tenants.
```bash
cd proxy
docker-compose up -d
```

### 2. Start a Tenant Environment
Navigate to the tenant template to spin up an isolated customer environment.
```bash
cd tenant-template
# Copy the example environment variables
cp .env.example .env

# Spin up the tenant stack (builds the custom PHP+SSH image on first run)
docker-compose up -d --build
```

### 3. SSH into a Tenant
The tenant `app` container runs a dedicated SSH daemon for secure, isolated CLI access (e.g., to run Laravel Artisan commands).
1. Ensure your public SSH key is added to `tenant-template/.ssh/authorized_keys` (you will need to create this file/folder if it doesn't exist yet).
2. Connect to the mapped SSH port (default is 2201):
```bash
ssh -p 2201 tenant@localhost
```

## Managing Multiple Tenants Locally
To prevent port clashing when running multiple tenants on the same machine, override the environment variables:
```bash
cd tenant-template
HTTP_PORT=9002 SSH_PORT=2202 TENANT_NAME=naivas docker-compose up -d
```


## Essential Tenant Commands

Because the Laravel application lives inside isolated Docker containers, you must run your Artisan commands *through* Docker or via SSH.

### Running Database Migrations
Since the environment is set to `production` by default for safety, you must force migrations or run them interactively:
```bash
# Non-interactive (Best for CI/CD or quick updates)
docker exec tenant-app-default php artisan migrate --force

# Interactive (Allows you to type 'yes' to prompts)
docker exec -it tenant-app-default php artisan migrate
```

### Running Tests
To ensure features and Role-Based Access Control (RBAC) are working as expected, run the PHPUnit test suite:
```bash
docker exec tenant-app-default php artisan test
```

### Other Common Artisan Commands
```bash
# Clear application caches
docker exec tenant-app-default php artisan optimize:clear

# Create a new controller (Requires interactive mode if prompting)
docker exec -it tenant-app-default php artisan make:controller MpesaController
```
