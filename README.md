# Ribath Masjid Hub — Backend API

REST API backend for **Ribath Masjid Hub**, a pesantren (Islamic boarding school) management system built for Ribath Masjid Riyadh Solo.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 12 |
| Language | PHP 8.2 |
| Database | PostgreSQL 18 |
| Auth | Laravel Sanctum |
| RBAC | Spatie Laravel Permission |
| Testing | Pest |

## Getting Started

### Prerequisites

- PHP >= 8.2 with `pgsql` and `pdo_pgsql` extensions
- Composer
- PostgreSQL

### Installation

```bash
# Clone the repository
git clone git@github.com:ak-rocksdev/ribath-backend.git
cd ribath-backend

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Create database and run migrations
php artisan migrate

# Seed roles, permissions, and admin user
php artisan db:seed
```

### Running

```bash
# Start the development server
composer dev

# Run tests
php artisan test
```

## API Overview

All endpoints are prefixed with `/api/v1/` and return consistent JSON responses:

```json
{
  "success": true,
  "data": {},
  "message": "Operation successful"
}
```

### Auth Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/login` | Login with email & password |
| POST | `/api/v1/auth/logout` | Revoke current token |
| GET | `/api/v1/auth/me` | Get authenticated user profile |
| PUT | `/api/v1/auth/change-password` | Change password |

## Project Structure

```
app/
├── Http/Controllers/Api/   # API controllers (versioned)
├── Http/Requests/          # Form Request validation
├── Models/                 # Eloquent models
├── Services/               # Business logic layer
└── Traits/                 # Reusable traits
```

## License

Proprietary — All rights reserved.
