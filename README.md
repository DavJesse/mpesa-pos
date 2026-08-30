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
