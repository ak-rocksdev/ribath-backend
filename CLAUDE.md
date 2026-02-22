# CLAUDE.md - Ribath Backend (Laravel 12 API)

This file provides guidance to Claude Code when working with this repository.

## Project Overview

**Ribath Backend** is the REST API backend for the **Ribath Masjid Hub** pesantren (Islamic boarding school) management system, built for Ribath Masjid Riyadh Solo. This project is a migration from Supabase (BaaS) to a self-hosted Laravel 12 API.

**Frontend:** React SPA at `C:\laragon\www\ribath-masjid-hub` (separate repo)
**Roadmap:** See `C:\laragon\www\ribath-masjid-hub\ROADMAP-MIGRASI-BACKEND.md` for full migration plan

## Development Philosophy

### Feature-by-Feature, Review-Before-Build

- **DO NOT** implement everything at once. Each feature is discussed, reviewed, and approved before implementation begins.
- Start from the **PSB (Pendaftaran Santri Baru)** flow — the process of receiving new students. After PSB, accepted students become new users in the system.
- Database tables and migrations are created **only when the feature needs them**, not all upfront. The existing Supabase schema is a reference, but we may adjust table structures during implementation based on review.
- Before creating any migration or model, **discuss the design first** — there may be improvements over the existing Supabase schema.

### Incremental Role Implementation

Roles are implemented gradually, not all at once:

| Priority | Roles | Status |
|---|---|---|
| **Now** | `super_admin`, `pengurus_pesantren` | Active — all current features use these |
| **Later** | `pengurus_pendidikan`, `pengurus_administrasi`, `ustadz` | Created in DB but features built incrementally |
| **Future** | `wali_santri` | Parent portal — built as a separate batch |

The app is **already in partial use**, so early batches must work with `super_admin` and `pengurus_pesantren` roles. Other role-specific features are added as those batches are developed.

### Mobile App Readiness

A mobile application (likely React Native or Flutter) will consume this same API in the future. Every API design decision must consider:
- **Stateless authentication** (Sanctum tokens, not session-based)
- **Consistent JSON response format** across all endpoints
- **Pagination on all list endpoints** (mobile needs efficient data loading)
- **File upload endpoints** that work for both web and mobile clients
- **Push notification infrastructure** (FCM tokens, device registration)
- **Offline-friendly patterns** (timestamps, sync-friendly responses, ETags where appropriate)

## Tech Stack

- **Framework:** Laravel 12 (API-only, no Blade views)
- **PHP:** 8.2.28
- **Database:** PostgreSQL 18 (standalone install at `C:\Program Files\PostgreSQL\18\`)
- **Auth:** Laravel Sanctum (SPA token-based)
- **RBAC:** Spatie Laravel Permission (`spatie/laravel-permission`)
- **Realtime:** Laravel Reverb (WebSocket, planned)
- **Queue:** Redis-backed (planned, currently database driver)
- **Cache:** Redis (planned, currently database driver)
- **Storage:** Laravel Filesystem (local → MinIO/S3 in production)
- **Testing:** Pest
- **Code Style:** Laravel Pint

## Local Environment

```
Apache:     port 80    (existing projects)
Nginx:      port 8181  (this project)
PostgreSQL: port 5432  (standalone, user: postgres)
MySQL:      port 3306  (other projects)
Redis:      port 6379  (enable in Laragon when needed)
Vite (FE):  port 8080  (React frontend dev server)
```

- Laragon runs both Apache and Nginx simultaneously
- PostgreSQL is NOT managed by Laragon — it's a standalone install at `C:\Program Files\PostgreSQL\18\`
- Database name: `ribath_backend`
- PHP extensions `pdo_pgsql` and `pgsql` enabled in `C:\laragon\bin\php\php-8.2.28-Win32-vs16-x64\php.ini`

## Development Commands

```bash
# Start dev server (artisan serve + queue + vite)
composer dev

# Run tests
php artisan test
# or
./vendor/bin/pest

# Run linter/formatter
./vendor/bin/pint

# Create migration
php artisan make:migration create_table_name_table

# Run migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Create model with migration, factory, seeder, controller, form request, policy
php artisan make:model ModelName -mfscr --policy

# Clear all caches
php artisan optimize:clear

# Generate IDE helper (if installed)
php artisan ide-helper:generate
```

## Architecture

### This is an API-Only Backend

- No Blade views, no Livewire, no Inertia
- All responses are JSON (`return response()->json(...)`)
- Frontend communicates via REST API + WebSocket
- CORS configured to accept requests from `localhost:8080` (React frontend)

### Directory Structure (Target)

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── Auth/          # Login, Register, Profile
│   │   │   ├── Admin/         # User mgmt, Roles, Settings, Bulk ops
│   │   │   ├── PSB/           # Pendaftaran Santri Baru
│   │   │   ├── Akademik/      # Santri, Ustadz, Kitab, Jadwal, Nilai, Absensi
│   │   │   ├── Tahfidz/       # Program, Setoran, Progress
│   │   │   ├── Keuangan/      # Tagihan, Pembayaran, Pengeluaran
│   │   │   ├── Konten/        # Berita, Galeri, Testimoni, FAQ, Prestasi
│   │   │   ├── Komunikasi/    # Notifications, Announcements
│   │   │   ├── Laporan/       # Reports, Analytics, Export
│   │   │   └── WaliSantri/    # Parent portal endpoints
│   │   └── Public/            # Public-facing endpoints (no auth)
│   ├── Middleware/
│   └── Requests/              # Form Request validation classes
├── Models/                    # Eloquent models
├── Policies/                  # Authorization policies
├── Services/                  # Business logic layer
├── Events/                    # Event classes for broadcasting
├── Jobs/                      # Queue jobs
├── Notifications/             # Notification classes (email, WhatsApp, push)
└── Console/Commands/          # Artisan commands
```

### Roles (Spatie Permission)

The system uses these roles (migrated from Supabase RBAC):

| Role | Description | Priority |
|---|---|---|
| `super_admin` | Full system access | **Active now** |
| `pengurus_pesantren` | Pesantren management staff | **Active now** |
| `pengurus_pendidikan` | Education department staff | Later |
| `pengurus_administrasi` | Administrative staff | Later |
| `ustadz` | Teacher | Later |
| `wali_santri` | Parent/guardian | Future |

### Database Schema

Tables are created **incrementally per batch**, not all at once. The existing Supabase schema (100+ tables) is a reference but may be adjusted during review.

**Reference table groups** (from Supabase — to be reviewed before implementation):
- **PSB:** `psb_periods`, `calon_santri_registrations`, `quick_registrations`
- **Akademik:** `santri`, `ustadz`, `kitab`, `jadwal_pelajaran`, `kelas`
- **Nilai:** `nilai_santri`, `absensi_harian`, `absensi_kegiatan`
- **Tahfidz:** `tahfidz_programs`, `tahfidz_assignments`, `tahfidz_progress`, `setoran_tahfidz`
- **Keuangan:** `jenis_tagihan`, `tagihan_santri`, `pembayaran`, `pengeluaran`
- **Konten:** `articles`, `gallery_items`, `testimonials`, `achievements`, `faq_items`
- **Auth:** `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`
- **Config:** `tahun_ajaran`, `site_config`, `academic_calendar_events`

**Important:** Do NOT create migrations for tables in future batches. Only create what the current batch needs.

## Development Batches

Development follows the roadmap in `ROADMAP-MIGRASI-BACKEND.md`:

| Batch | Feature | Status |
|---|---|---|
| 0 | Foundation (Auth, RBAC, Config) | Not started |
| 1 | PSB (Pendaftaran Santri Baru) | Not started |
| 2 | User & Santri Management | Not started |
| 3 | Ustadz & SDM | Not started |
| 4 | Kurikulum & Scheduling | Not started |
| 5 | Absensi (Attendance) | Not started |
| 6 | Nilai (Grading) | Not started |
| 7 | Tahfidz (Quran Memorization) | Not started |
| 8 | Keuangan (Finance) | Not started |
| 9 | Landing Page & Public Content | Not started |
| 10 | Notifications & Realtime | Not started |
| 11 | Reports & Export | Not started |
| 12 | Wali Santri Portal | Not started |
| 13 | Frontend Migration | Not started |
| 14 | Go-Live & Cutover | Not started |

## Important Conventions

### API Response Format

Always use consistent JSON response structure:
```php
// Success
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => 'Operation successful'
]);

// Error
return response()->json([
    'success' => false,
    'message' => 'Error description',
    'errors' => $errors  // validation errors if applicable
], 422);

// Paginated
return response()->json([
    'success' => true,
    'data' => $items->items(),
    'meta' => [
        'current_page' => $items->currentPage(),
        'last_page' => $items->lastPage(),
        'per_page' => $items->perPage(),
        'total' => $items->total(),
    ]
]);
```

### Code Style

- **PSR-12** via Laravel Pint
- **Naming:** Models PascalCase, tables snake_case, routes kebab-case
- **Controllers:** Always use Form Request classes for validation (never validate in controller)
- **Business logic:** In Service classes, not in Controllers
- **Authorization:** Use Policies + Spatie Permission middleware
- **API versioning:** Routes prefixed with `/api/v1/`

### Security

- Never log passwords, tokens, or personal data
- Use Form Request validation on all endpoints
- Apply Spatie Permission middleware on all protected routes
- Use Eloquent relationships to prevent N+1 queries
- Use `$fillable` on all models (never `$guarded = []`)

### Testing

- Write Pest tests for all API endpoints
- Use factories for test data
- Test both authorized and unauthorized access
- Test validation rules

## Deployment Target

- **Server:** VPS with Nginx + PHP-FPM + PostgreSQL + Redis
- **Locally:** Laragon (Nginx on port 8181)
- **Frontend:** Deployed separately on the Same VPS with Nginx (React SPA)
