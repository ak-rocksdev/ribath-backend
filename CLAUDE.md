# CLAUDE.md — Ribath Backend (Laravel 12 API)

Guidance for Claude Code in this repository.

## Project

REST API for **Ribath Masjid Hub** (pesantren management for Ribath Masjid Riyadh Solo), replacing the original Supabase backend. Consumed by the React SPA in the sibling repo `ribath-masjid-hub` and, later, a mobile app — so endpoints stay stateless (Sanctum tokens), list endpoints paginate, file uploads work for web and mobile, and events that need live updates implement `ShouldBroadcast`.

Stack: Laravel 12, PostgreSQL 16, Sanctum, Spatie Permission, Reverb, spatie/laravel-pdf (Browsershot + Chromium), Pest, Pint.

**Production runs PHP 8.2** (FPM and CLI) even where local runs newer — write PHP 8.2-compatible code.

## Status

In production. Built here: auth, users/roles, school profile, master data (tahun ajaran, kelas, mapel/kitab, jam pelajaran, ustadz), PSB, santri, jadwal (+ PDF export), keuangan (fee types/schedules, per-student fee assignments and exceptions, bills, payments, buku kas), notifications.

Not yet built here (still on Supabase in the frontend): absensi, nilai, tahfidz, landing-page content, wali-santri portal.

Batch plan and history: `docs/ROADMAP-MIGRASI-BACKEND.md`. Per-feature designs and plans: `docs/plans/`, plus spec-kit and design docs in the frontend repo (`specs/`, `docs/superpowers/specs/`).

## Supabase is reference, not a spec

For a feature not built yet, study the Supabase version — schema in the frontend repo (`supabase/migrations/`, `all_db_schema_table.sql`), screens in its `src/pages/<feature>/` — to learn the business intent. Then design the Laravel version on its own merits (English names, current conventions, better structure) and propose it for review. Parts of the old system are already outdated; carry over only what is still right.

## How features get built

- One feature at a time: discuss and get the design approved — tables, models, endpoints — before writing code.
- Create tables only when the current feature needs them.

## Architecture

- API-only. Routes in `routes/api.php` under `/api/v1` (kebab-case); controllers in `app/Http/Controllers/Api/{Auth,Admin,PSB,Keuangan,Public}/`.
- Thin controllers: validate with a Form Request (`app/Http/Requests/`), delegate business logic to a Service (`app/Services/`, `app/Services/Keuangan/`), respond through `ApiResponseTrait` (`successResponse`, `errorResponse`, `paginatedResponse`) → `{ success, data, message }` plus `errors` or `meta`.
- **Tenancy:** records belong to a school via `school_id`. The service assigns it **server-side** from `School::activeOrFail()` — request input never sets it. Controllers guard with `ensureBelongsToActiveSchool()` (`app/Traits/EnsuresActiveSchoolTenancy.php`, 404 on mismatch), so a row saved without `school_id` is invisible to every guarded endpoint — the cause of the manual-student fee bug (`docs/plans/2026-09-12-fix-manual-student-school-id.md`).
- **Authorization: Spatie Permission only** — `permission:` route middleware and `$user->hasPermissionTo()`, permissions named `{action}-{resource}` and seeded in `RolePermissionSeeder`; `super_admin` passes every check (`Gate::before` in `AppServiceProvider`). Laravel Policy classes are not used.
- Roles: `super_admin`, `pengurus_pesantren` (active); `pengurus_pendidikan`, `pengurus_administrasi`, `ustadz`, `wali_santri` (exist; their features arrive per batch).
- Scheduled jobs: `routes/console.php` (e.g. `fee:generate-bills` daily at 00:01); VPS cron: `docs/VPS-CRON-JOBS.md`.

## Conventions

- Descriptive names over short ones — `$activeRegistrationPeriod`, `calculateTotalOutstandingPayments()` — so code reads without comments.
- `$fillable` on every model; eager-load relations to avoid N+1.
- Never log passwords, tokens, or personal data.
- PSR-12 via `./vendor/bin/pint`.

## Testing

- `./vendor/bin/pest` — SQLite in-memory with `RefreshDatabase`, so migrations must run on SQLite and PostgreSQL alike (query builder, no Postgres-only SQL).
- Each endpoint: authorized and unauthorized access, validation. Where a bug could hide in record creation, create test data through the real endpoint — factories fill fields the endpoint may forget (`StudentFactory` leaves `school_id` null, which hid the manual-student bug).

## Local development

- `.env` from `.env.example`: `DB_CONNECTION=pgsql`, database `ribath_app_local` (dev server `ribath_app_dev`, production `ribath_app_prod`). The local DB is often a copy of production — real personal data.
- Serve with Herd/Laragon; `APP_URL` must match the host in the frontend's `VITE_API_BASE_URL`. Realtime: `php artisan reverb:start` (port 6001); the frontend's `VITE_REVERB_APP_KEY` must equal `REVERB_APP_KEY`.
- Match production's PHP 8.2 locally. Under Herd the site is isolated to 8.2 (`herd isolate 8.2`), but plain `php` in the terminal is Herd's global version — run CLI work through the isolated one: `herd php artisan …`, `herd php vendor/bin/pest`, `herd composer …`.
- Schema changes: `php artisan migrate`. `migrate:fresh`, `migrate:refresh`, `migrate:reset` and `db:wipe` are blocked unless `DB_ALLOW_DESTRUCTIVE=true`; for a clean slate use `php artisan db:reset-local --seed` (interactive).
- `DatabaseSeeder`: school, class levels, roles/permissions + default admin, subject categories, time slots.

## Deployment (production VPS)

API at `https://apiribath.hyperscore.cloud`, Capistrano-style under `/srv/www/ribath-backend/` (`releases/`, `current` symlink, `shared/env/.env`, `shared/storage/`, `backups/`). SSH: `ak_rocks@103.157.97.233` (key auth).

```bash
git push origin main   # deploy.sh clones from GitHub
ssh ak_rocks@103.157.97.233 "bash /srv/www/ribath-backend/scripts/deploy.sh --migrate"
```

- Flags (`deploy.sh --help`): `--migrate` runs migrations and **always pg_dumps to `backups/` first** — without it migrations are skipped; `--seed` runs seeders (new permissions/roles/class levels); `--branch=<name>`.
- Reverb and the queue worker run under Supervisor as `www-data`; deploy restarts them via `artisan reverb:restart` / `queue:restart` because the deploy user has no sudo for `supervisorctl`.
- The VPS runs its own copy of `scripts/deploy.sh` outside `releases/`; after changing it here, copy it to `/srv/www/ribath-backend/scripts/deploy.sh`.
- Reverb behind Nginx: `docs/DEPLOYMENT-REVERB.md`.
- **Deploy only on an explicit user instruction.**
