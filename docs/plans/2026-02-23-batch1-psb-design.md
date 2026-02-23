# Batch 1: PSB (Pendaftaran Santri Baru) — Design Document

## Goal

Build the PSB (student registration) API: a public quick interest form, admin lead management, PSB period CRUD, and an acceptance flow that creates santri + wali user records.

## Context

The PSB form is a simple ~10 field interest form (not the full 9-step wizard at `/pendaftaran`, which is a separate process for later). After submission, admin contacts leads, interviews them (phone/in-person), and decides to accept or reject. On acceptance, a `santri` record is created, plus a `users` record for the wali if guardian data exists.

### Frontend Reference

The React frontend at `C:\laragon\www\ribath-masjid-hub` has:
- `/psb` — Quick interest form (QuickInterestForm.tsx)
- `/admin/pendaftaran-masuk` — Lead management panel
- `/admin/pendaftaran-review` — Review & approval dashboard
- `/admin/landing-psb` — PSB period management

Currently these talk to Supabase directly. The Laravel API field names stay compatible to minimize future frontend migration effort.

---

## Data Model

### Table: `psb_periods`

Registration period configuration (gelombang, dates, quota, fees).

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | PK | gen_random_uuid | |
| name | varchar(100) | no | | e.g. "Pendaftaran 2026/2027" |
| year | varchar(20) | no | | e.g. "2026/2027" |
| gelombang | integer | no | | Wave number (1, 2, ...) |
| pendaftaran_buka | timestamp | no | | Registration opens |
| pendaftaran_tutup | timestamp | no | | Registration closes |
| tanggal_masuk | date | no | | Student entry date |
| biaya_pendaftaran | decimal(12,2) | no | 0 | Registration fee (IDR) |
| biaya_spp_bulanan | decimal(12,2) | no | 0 | Monthly tuition (IDR) |
| kuota_santri | integer | yes | null | Max capacity (null = unlimited) |
| kuota_terisi | integer | no | 0 | Current filled count |
| description | text | yes | | |
| is_active | boolean | no | true | Visible to public |
| created_at | timestamp | no | now() | |
| updated_at | timestamp | no | now() | |

**Validation:**
- `pendaftaran_tutup` must be after `pendaftaran_buka`
- `tanggal_masuk` must be after `pendaftaran_tutup`
- `kuota_santri` must be positive if set

### Table: `psb_registrations`

Quick interest form submissions + admin lead tracking.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | PK | gen_random_uuid | |
| psb_period_id | uuid FK | yes | | Links to active period |
| registration_number | varchar | unique | auto | PSB-2026-00001 |
| status | varchar(20) | no | 'baru' | See status enum |
| registrant_type | varchar(10) | no | | 'wali' or 'santri' |
| nama_lengkap | varchar(100) | no | | Student name |
| tempat_lahir | varchar(100) | yes | | |
| tanggal_lahir | date | no | | |
| jenis_kelamin | varchar(1) | no | | 'L' or 'P' |
| program_minat | varchar(20) | no | | 'tahfidz' or 'regular' |
| nama_wali | varchar(100) | yes | | Guardian name (null if registrant_type='santri') |
| no_hp_wali | varchar(20) | no | | WhatsApp number |
| email_wali | varchar(255) | yes | | |
| sumber_info | varchar(50) | yes | | Source of information |
| admin_notes | text | yes | | Internal notes |
| contacted_at | timestamp | yes | | When admin contacted |
| contacted_by | uuid FK | yes | | Who contacted |
| interviewed_at | timestamp | yes | | Interview date |
| reviewed_at | timestamp | yes | | Decision timestamp |
| reviewed_by | uuid FK | yes | | Who accepted/rejected |
| rejection_reason | text | yes | | Required on rejection |
| created_at | timestamp | no | now() | |
| updated_at | timestamp | no | now() | |
| deleted_at | timestamp | yes | | Soft delete |

**Indexes:**
- `status` — frequent filter
- `psb_period_id` — join
- `registration_number` — lookup
- `created_at DESC` — default sort

### Table: `santri`

Created on acceptance. Minimal columns for Batch 1; expanded in Batch 2.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | PK | gen_random_uuid | |
| psb_registration_id | uuid FK | yes | | Links back to PSB lead |
| wali_user_id | uuid FK→users | yes | | Parent's login (null if self-registered) |
| user_id | uuid FK→users | yes | | Student's own login (future, always null for now) |
| nama_lengkap | varchar(100) | no | | |
| tempat_lahir | varchar(100) | yes | | |
| tanggal_lahir | date | no | | |
| jenis_kelamin | varchar(1) | no | | 'L' or 'P' |
| program | varchar(20) | no | | 'tahfidz' or 'regular' |
| status | varchar(20) | no | 'aktif' | 'aktif', 'nonaktif', 'lulus', 'keluar' |
| tanggal_masuk | date | no | | From psb_period.tanggal_masuk |
| created_at | timestamp | no | now() | |
| updated_at | timestamp | no | now() | |

---

## Status Flow (7 statuses)

```
baru → dihubungi → interview → diterima
                             → ditolak
                             → waitlist → diterima (later, if spot opens)
         (any state) → batal (cancelled by applicant)
```

| Status | Indonesian Label | Description |
|--------|------------------|-------------|
| `baru` | Baru | New lead, just submitted |
| `dihubungi` | Sudah Dihubungi | Admin contacted via WA/phone |
| `interview` | Interview | Interview scheduled or done |
| `diterima` | Diterima | Accepted — triggers santri + wali creation |
| `ditolak` | Ditolak | Rejected (reason required) |
| `waitlist` | Daftar Tunggu | Waiting list (quota full or pending decision) |
| `batal` | Batal | Cancelled by applicant |

---

## Acceptance Flow

```
Admin clicks "Diterima" on PSB registration
│
├── Create santri record
│   - nama_lengkap, tempat_lahir, tanggal_lahir, jenis_kelamin from registration
│   - program from program_minat
│   - tanggal_masuk from psb_period.tanggal_masuk
│   - status = 'aktif'
│
├── Has wali data? (nama_wali is not null)
│   ├── YES
│   │   ├── Create user record
│   │   │   - name = nama_wali
│   │   │   - email = email_wali (or generated: 08xxx@wali.ribath.local)
│   │   │   - password = auto-generated temp password
│   │   │   - role = wali_santri (Spatie)
│   │   ├── Link santri.wali_user_id = new user
│   │   └── Return temp credentials in response (for admin to share via WA)
│   │
│   └── NO (registrant_type = 'santri', no guardian)
│       └── santri.wali_user_id = null
│
├── Update registration
│   - status = 'diterima'
│   - reviewed_at = now()
│   - reviewed_by = admin user id
│
├── Update psb_period
│   - kuota_terisi += 1
│
└── Return: santri data + wali credentials (if created)
```

---

## API Endpoints

### Public (no auth, prefix: `/api/v1/public/psb`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/active-period` | Get currently active PSB period |
| POST | `/register` | Submit quick interest form |

### Admin — PSB Periods (prefix: `/api/v1/psb/periods`)

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| GET | `/` | view-psb-periods | List all periods (paginated) |
| POST | `/` | manage-psb-periods | Create period |
| GET | `/{id}` | view-psb-periods | Show period |
| PUT | `/{id}` | manage-psb-periods | Update period |
| DELETE | `/{id}` | manage-psb-periods | Delete (only if no registrations) |

### Admin — Registrations (prefix: `/api/v1/psb/registrations`)

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| GET | `/` | view-psb-registrations | List (filter, search, paginate) |
| GET | `/stats` | view-psb-registrations | Count per status |
| GET | `/{id}` | view-psb-registrations | Detail |
| PATCH | `/{id}/status` | manage-psb-registrations | Update status (dihubungi, interview, waitlist, batal) |
| POST | `/{id}/accept` | manage-psb-registrations | Accept → create santri + wali |
| POST | `/{id}/reject` | manage-psb-registrations | Reject with reason |
| DELETE | `/{id}` | manage-psb-registrations | Soft delete |

### New Permissions (added to RolePermissionSeeder)

| Permission | Assigned to |
|------------|-------------|
| view-psb-registrations | pengurus_pesantren |
| manage-psb-registrations | pengurus_pesantren |
| view-psb-periods | pengurus_pesantren |
| manage-psb-periods | pengurus_pesantren |

(super_admin has all permissions via Gate::before bypass)

---

## Components & Files

| Layer | Files to Create |
|-------|----------------|
| **Migrations** | `create_psb_periods_table`, `create_psb_registrations_table`, `create_santri_table` |
| **Models** | `PsbPeriod`, `PsbRegistration`, `Santri` |
| **Controllers** | `PublicPsbController`, `PsbPeriodController`, `PsbRegistrationController` |
| **Services** | `PsbService` (register, accept, reject, stats), `RegistrationNumberGenerator` |
| **Form Requests** | `QuickRegistrationRequest`, `StorePsbPeriodRequest`, `UpdatePsbPeriodRequest`, `UpdateRegistrationStatusRequest`, `RejectRegistrationRequest` |
| **Factories** | `PsbPeriodFactory`, `PsbRegistrationFactory`, `SantriFactory` |
| **Seeder** | Update `RolePermissionSeeder` with 4 new permissions |
| **Tests** | Full feature tests for all endpoints |

---

## Frontend Compatibility Notes

The React frontend currently uses Supabase SDK directly. When migrating (Batch 13), these mappings apply:

| Frontend (current) | Laravel API (new) | Notes |
|--------------------|--------------------|-------|
| `supabase.from('calon_santri_registrations')` | `POST /api/v1/public/psb/register` | New endpoint |
| `supabase.from('psb_periods')` | `GET /api/v1/public/psb/active-period` | Public endpoint |
| `supabase.from('landing_psb_periods')` | Same endpoint above | Merged — no duplicate table |
| Status `'interest'` | Status `'baru'` | Renamed to Indonesian |
| Status `'contacted'` | Status `'dihubungi'` | Renamed |
| Status `'scheduled_visit'` / `'visited'` | Status `'interview'` | Merged into one |
| Status `'completing'` / `'pending'` / `'under_review'` | Removed | No full wizard flow |
| Status `'approved'` | Status `'diterima'` | Renamed |
| Status `'rejected'` | Status `'ditolak'` | Renamed |
| Gender `'Laki-laki'` / `'Perempuan'` | Gender `'L'` / `'P'` | Shortened, frontend already uses L/P internally |
| `jenis_kelamin` (field name) | `jenis_kelamin` | Same |
| `no_hp_wali` (field name) | `no_hp_wali` | Same |
| `completion_token` | Not used | Full wizard is separate/later |
| `is_complete` | Not used | No wizard completion tracking |
| `pesantren_id` | Not used | Single-tenant for now |

---

## Out of Scope (Future Batches)

- Full registration wizard (`/pendaftaran`) — separate process/table
- WhatsApp notification integration
- Document uploads for PSB
- Email credential delivery
- Student self-login (user_id on santri)
- Wali santri portal
