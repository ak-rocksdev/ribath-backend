# Roadmap Migrasi Backend: Supabase ke Laravel 12 + PostgreSQL

> Dokumen ini berisi perencanaan migrasi sistem **Ribath App** dari Supabase (BaaS) ke **Laravel 12** dengan PostgreSQL self-hosted. Aplikasi ini dirancang untuk dapat digunakan oleh **pesantren manapun** (generic). Pendekatan: **fitur demi fitur (incremental)**, dimulai dari alur PSB hingga seluruh sistem berjalan mandiri.
>
> **Catatan Penting:**
> - Otorisasi menggunakan **Spatie Permission saja** (tanpa Laravel Policy) — semua permission dari database
> - Role yang aktif saat ini: `super_admin` dan `pengurus_pesantren`. Role lain diimplementasi bertahap
> - API dirancang untuk **multi-client**: React web app + future mobile app (React Native/Flutter)
> - **Laravel Reverb** digunakan untuk live notification & broadcasting ke semua client
> - Database naming: `ribath_app_local` (lokal), `ribath_app_dev` (dev), `ribath_app_prod` (production)

---

## Daftar Isi

1. [Arsitektur Target](#1-arsitektur-target)
2. [Struktur Project Laravel 12](#2-struktur-project-laravel-12)
3. [Prasyarat & Setup Awal](#3-prasyarat--setup-awal)
4. [Batch 0: Foundation](#batch-0-foundation-minggu-1-3)
5. [Batch 1: PSB - Pendaftaran Santri Baru](#batch-1-psb---pendaftaran-santri-baru-minggu-4-6)
6. [Batch 2: Manajemen User & Santri](#batch-2-manajemen-user--santri-minggu-7-9)
7. [Batch 3: Manajemen Ustadz & SDM](#batch-3-manajemen-ustadz--sdm-minggu-10-11)
8. [Batch 4: Kurikulum & Penjadwalan](#batch-4-kurikulum--penjadwalan-minggu-12-14)
9. [Batch 5: Absensi & Presensi](#batch-5-absensi--presensi-minggu-15-16)
10. [Batch 6: Penilaian & Evaluasi Akademik](#batch-6-penilaian--evaluasi-akademik-minggu-17-19)
11. [Batch 7: Tahfidz (Hafalan Al-Quran)](#batch-7-tahfidz-hafalan-al-quran-minggu-20-22)
12. [Batch 8: Keuangan & Pembayaran](#batch-8-keuangan--pembayaran-minggu-23-26)
13. [Batch 9: Landing Page & Konten Publik](#batch-9-landing-page--konten-publik-minggu-27-29)
14. [Batch 10: Notifikasi, Realtime & Komunikasi](#batch-10-notifikasi-realtime--komunikasi-minggu-30-32)
15. [Batch 11: Laporan, Analitik & Export](#batch-11-laporan-analitik--export-minggu-33-34)
16. [Batch 12: Portal Wali Santri](#batch-12-portal-wali-santri-minggu-35-36)
17. [Batch 13: Migrasi Frontend](#batch-13-migrasi-frontend-paralel-dengan-setiap-batch)
18. [Batch 14: Go-Live & Cutover](#batch-14-go-live--cutover-minggu-37-40)
19. [Ringkasan Timeline](#ringkasan-timeline)
20. [Risiko & Mitigasi](#risiko--mitigasi)

---

## 1. Arsitektur Target

```
                     +-------------------------+
                     |   React Frontend (SPA)  |
                     |   Vite + TanStack Query |
                     +-----------+-------------+
                                 |
                          REST API (JSON)
                          + WebSocket (Laravel Reverb)
                                 |
                     +-----------v-------------+
                     |      Laravel 12         |
                     |  ┌───────────────────┐  |
                     |  │ Sanctum (Auth)    │  |
                     |  │ Spatie Permission │  |
                     |  │ Laravel Reverb    │  |
                     |  │ Queue (Redis)     │  |
                     |  │ Task Scheduling   │  |
                     |  │ Storage (S3/Local)│  |
                     |  └───────────────────┘  |
                     +-----------+-------------+
                                 |
                  +--------------+--------------+
                  |              |              |
            +-----v-----+ +-----v-----+ +-----v-----+
            | PostgreSQL | |   Redis   | |  MinIO /  |
            |  Database  | | Cache +   | |  Local FS |
            |            | | Queue +   | | (Storage) |
            |            | | Reverb    | |           |
            +------------+ +-----------+ +-----------+
```

### Mapping Supabase → Laravel

| Supabase Feature | Laravel Equivalent | Package |
|---|---|---|
| Supabase Auth | **Laravel Sanctum** | `laravel/sanctum` |
| Row Level Security (RLS) | **Spatie Permission** + Middleware (tanpa Laravel Policy) | `spatie/laravel-permission` |
| Database + RPC Functions | **Eloquent ORM** + Query Builder | Built-in |
| Realtime (WebSocket) | **Laravel Reverb** | `laravel/reverb` |
| Storage Buckets | **Laravel Filesystem** (S3/MinIO/local) | Built-in |
| Edge Functions | **Laravel Queue** + **Task Scheduling** | Built-in |
| Public Client (anon) | Public API routes (tanpa middleware auth) | Built-in |

---

## 2. Struktur Project Laravel 12

```
ribath-backend/
├── app/
│   ├── Console/
│   │   └── Commands/              # Artisan commands (bootstrap admin, dll)
│   ├── Events/                    # Event classes (untuk broadcasting)
│   ├── Exceptions/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── Auth/
│   │   │   │   │   ├── LoginController.php
│   │   │   │   │   ├── RegisterController.php
│   │   │   │   │   └── ProfileController.php
│   │   │   │   ├── Admin/
│   │   │   │   │   ├── UserController.php
│   │   │   │   │   ├── RolePermissionController.php
│   │   │   │   │   ├── TahunAjaranController.php
│   │   │   │   │   ├── SiteConfigController.php
│   │   │   │   │   └── BulkOperationController.php
│   │   │   │   ├── PSB/
│   │   │   │   │   ├── QuickRegistrationController.php
│   │   │   │   │   ├── FullRegistrationController.php
│   │   │   │   │   ├── RegistrationReviewController.php
│   │   │   │   │   └── PSBPeriodController.php
│   │   │   │   ├── Akademik/
│   │   │   │   │   ├── SantriController.php
│   │   │   │   │   ├── UstadzController.php
│   │   │   │   │   ├── KitabController.php
│   │   │   │   │   ├── JadwalController.php
│   │   │   │   │   ├── NilaiController.php
│   │   │   │   │   ├── AbsensiController.php
│   │   │   │   │   └── EvaluasiController.php
│   │   │   │   ├── Tahfidz/
│   │   │   │   │   ├── ProgramController.php
│   │   │   │   │   ├── SetoranController.php
│   │   │   │   │   ├── JuzProgressController.php
│   │   │   │   │   └── AssignmentController.php
│   │   │   │   ├── Keuangan/
│   │   │   │   │   ├── JenisTagihanController.php
│   │   │   │   │   ├── TagihanController.php
│   │   │   │   │   ├── PembayaranController.php
│   │   │   │   │   └── PengeluaranController.php
│   │   │   │   ├── Konten/
│   │   │   │   │   ├── BeritaController.php
│   │   │   │   │   ├── GaleriController.php
│   │   │   │   │   ├── TestimoniController.php
│   │   │   │   │   ├── AchievementController.php
│   │   │   │   │   ├── FAQController.php
│   │   │   │   │   └── MediaController.php
│   │   │   │   ├── Komunikasi/
│   │   │   │   │   ├── NotificationController.php
│   │   │   │   │   ├── AnnouncementController.php
│   │   │   │   │   └── MessageController.php
│   │   │   │   ├── Laporan/
│   │   │   │   │   ├── ReportController.php
│   │   │   │   │   ├── AnalyticsController.php
│   │   │   │   │   └── ExportController.php
│   │   │   │   └── WaliSantri/
│   │   │   │       ├── ProgressAnakController.php
│   │   │   │       ├── TagihanAnakController.php
│   │   │   │       └── AbsensiAnakController.php
│   │   │   └── Public/
│   │   │       ├── LandingController.php
│   │   │       ├── PublicBeritaController.php
│   │   │       ├── PublicGaleriController.php
│   │   │       └── PublicPSBController.php
│   │   ├── Middleware/
│   │   │   └── EnsurePesantrenContext.php    # Multi-tenant context
│   │   ├── Requests/                         # Form Requests (validasi)
│   │   │   ├── PSB/
│   │   │   │   ├── QuickRegistrationRequest.php
│   │   │   │   └── FullRegistrationRequest.php
│   │   │   ├── Akademik/
│   │   │   │   ├── StoreSantriRequest.php
│   │   │   │   ├── StoreNilaiRequest.php
│   │   │   │   └── StoreAbsensiRequest.php
│   │   │   └── Keuangan/
│   │   │       ├── StoreTagihanRequest.php
│   │   │       └── StorePembayaranRequest.php
│   │   └── Resources/                        # API Resources (transformasi JSON)
│   │       ├── SantriResource.php
│   │       ├── UstadzResource.php
│   │       ├── RegistrationResource.php
│   │       ├── NilaiResource.php
│   │       └── TagihanResource.php
│   ├── Jobs/                                  # Queued Jobs
│   │   ├── GenerateMonthlyInvoices.php
│   │   ├── SendTahfidzDailyReport.php
│   │   ├── SendTahfidzWeeklySummary.php
│   │   ├── FetchPrayerTimes.php
│   │   ├── CleanupExpiredNotifications.php
│   │   └── SendCredentialsToUser.php
│   ├── Listeners/
│   │   ├── SendRegistrationNotification.php
│   │   └── LogAuditTrail.php
│   ├── Models/
│   │   ├── User.php                           # extends Authenticatable, HasRoles
│   │   ├── Pesantren.php
│   │   ├── Santri.php
│   │   ├── Ustadz.php
│   │   ├── Khodim.php
│   │   ├── CalonSantriRegistration.php
│   │   ├── PSBPeriod.php
│   │   ├── TahunAjaran.php
│   │   ├── FannCategory.php
│   │   ├── Kitab.php
│   │   ├── SlotWaktu.php
│   │   ├── JadwalMengajar.php
│   │   ├── AbsensiHarian.php
│   │   ├── AbsensiKegiatan.php
│   │   ├── NilaiSantri.php
│   │   ├── EvaluasiSantri.php
│   │   ├── TahfidzProgram.php
│   │   ├── TahfidzEnrollment.php
│   │   ├── TahfidzJuzProgress.php
│   │   ├── TahfidzSetoran.php
│   │   ├── JenisTagihan.php
│   │   ├── TagihanSantri.php
│   │   ├── Pembayaran.php
│   │   ├── Notification.php
│   │   ├── Announcement.php
│   │   ├── BeritaArticle.php
│   │   ├── BeritaCategory.php
│   │   ├── GaleriItem.php
│   │   ├── TestimoniEntry.php
│   │   ├── AchievementEntry.php
│   │   ├── FAQEntry.php
│   │   ├── MediaLibrary.php
│   │   ├── AuditLog.php
│   │   └── SiteConfig.php
│   ├── Notifications/                         # Laravel Notification classes
│   │   ├── NewRegistrationNotification.php
│   │   ├── PaymentReceivedNotification.php
│   │   └── ScheduleChangedNotification.php
│   ├── Observers/                             # Model Observers
│   │   ├── RegistrationObserver.php           # Auto-generate nomor pendaftaran
│   │   ├── TagihanObserver.php
│   │   └── AuditLogObserver.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── Services/                              # Business Logic
│       ├── RegistrationNumberGenerator.php
│       ├── InvoiceGenerator.php
│       ├── AttendanceService.php
│       ├── TahfidzService.php
│       └── ReportExportService.php
├── config/
│   ├── permission.php                         # Spatie Permission config
│   └── reverb.php                             # Laravel Reverb config
├── database/
│   ├── migrations/                            # Laravel migrations
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   ├── RolePermissionSeeder.php           # Seed semua roles & permissions
│   │   ├── MasterDataSeeder.php               # Slot waktu, fann categories, dll
│   │   └── SuperAdminSeeder.php
│   └── factories/
├── routes/
│   ├── api.php                                # Semua API routes
│   ├── channels.php                           # Broadcasting channels
│   └── console.php                            # Scheduled commands
├── storage/
│   └── app/
│       ├── public/                            # Public files (foto profil, galeri, dll)
│       └── private/                           # Private files (dokumen, bukti bayar)
├── tests/
│   ├── Feature/
│   │   ├── Auth/
│   │   ├── PSB/
│   │   ├── Akademik/
│   │   ├── Keuangan/
│   │   └── Konten/
│   └── Unit/
├── docker-compose.yml
├── .env.example
└── composer.json
```

### Route Structure (`routes/api.php`)

```php
<?php

use Illuminate\Support\Facades\Route;

// ================================================================
// PUBLIC ROUTES (tanpa auth)
// ================================================================
Route::prefix('public')->group(function () {
    // Landing Page Data
    Route::get('landing', [LandingController::class, 'index']);
    Route::get('berita', [PublicBeritaController::class, 'index']);
    Route::get('berita/{slug}', [PublicBeritaController::class, 'show']);
    Route::get('galeri', [PublicGaleriController::class, 'index']);
    Route::get('testimoni', [LandingController::class, 'testimoni']);
    Route::get('achievement', [LandingController::class, 'achievement']);
    Route::get('faq', [LandingController::class, 'faq']);
    Route::get('psb-periods', [PublicPSBController::class, 'activePeriods']);
    Route::get('contact', [LandingController::class, 'contact']);

    // PSB Quick Registration (publik, tanpa login)
    Route::post('psb/quick-register', [PublicPSBController::class, 'quickRegister']);
    Route::get('psb/registration/{token}', [PublicPSBController::class, 'getByToken']);
    Route::put('psb/registration/{token}/complete', [PublicPSBController::class, 'completeRegistration']);
});

// ================================================================
// AUTH ROUTES
// ================================================================
Route::prefix('auth')->group(function () {
    Route::post('login', [LoginController::class, 'login']);
    Route::post('logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('me', [ProfileController::class, 'me'])->middleware('auth:sanctum');
    Route::patch('profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');
    Route::post('change-password', [ProfileController::class, 'changePassword'])->middleware('auth:sanctum');
});

// ================================================================
// AUTHENTICATED ROUTES
// ================================================================
Route::middleware(['auth:sanctum'])->group(function () {

    // Dashboard
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);

    // ---- PSB (Admin) ----
    Route::apiResource('psb/periods', PSBPeriodController::class);
    Route::get('psb/registrations', [RegistrationReviewController::class, 'index']);
    Route::get('psb/registrations/{id}', [RegistrationReviewController::class, 'show']);
    Route::patch('psb/registrations/{id}/status', [RegistrationReviewController::class, 'updateStatus']);
    Route::post('psb/registrations/soft-delete', [RegistrationReviewController::class, 'softDelete']);

    // ---- User Management ----
    Route::apiResource('users', UserController::class);
    Route::post('users/{id}/send-credentials', [UserController::class, 'sendCredentials']);
    Route::patch('users/{id}/password', [UserController::class, 'updatePassword']);
    Route::get('roles-permissions', [RolePermissionController::class, 'index']);
    Route::post('roles-permissions/assign', [RolePermissionController::class, 'assignRole']);

    // ---- Santri ----
    Route::apiResource('santri', SantriController::class);
    Route::get('santri/{id}/detail', [SantriController::class, 'detail']);
    Route::post('santri/bulk-import', [BulkOperationController::class, 'importSantri']);

    // ---- Ustadz ----
    Route::apiResource('ustadz', UstadzController::class);
    Route::post('ustadz/bulk-import', [BulkOperationController::class, 'importUstadz']);
    Route::post('ustadz/replace-tahfidz', [UstadzController::class, 'replaceTahfidz']);

    // ---- Master Data ----
    Route::apiResource('tahun-ajaran', TahunAjaranController::class);
    Route::apiResource('fann-categories', FannCategoryController::class);
    Route::apiResource('kitab', KitabController::class);
    Route::apiResource('slot-waktu', SlotWaktuController::class);
    Route::apiResource('jenis-tagihan', JenisTagihanController::class);

    // ---- Jadwal ----
    Route::apiResource('jadwal', JadwalController::class);
    Route::get('jadwal/conflicts', [JadwalController::class, 'conflicts']);
    Route::post('jadwal/bulk-import', [BulkOperationController::class, 'importJadwal']);

    // ---- Absensi ----
    Route::apiResource('absensi/harian', AbsensiHarianController::class);
    Route::apiResource('absensi/kegiatan', AbsensiKegiatanController::class);
    Route::get('absensi/rekap-bulanan', [AbsensiController::class, 'rekapBulanan']);
    Route::post('absensi/bulk', [AbsensiController::class, 'bulkStore']);

    // ---- Nilai & Evaluasi ----
    Route::apiResource('nilai', NilaiController::class);
    Route::post('nilai/bulk', [NilaiController::class, 'bulkStore']);
    Route::apiResource('evaluasi', EvaluasiController::class);

    // ---- Tahfidz ----
    Route::apiResource('tahfidz/program', TahfidzProgramController::class);
    Route::apiResource('tahfidz/setoran', TahfidzSetoranController::class);
    Route::apiResource('tahfidz/juz-progress', TahfidzJuzProgressController::class);
    Route::get('tahfidz/assignments', [TahfidzAssignmentController::class, 'index']);
    Route::post('tahfidz/assignments', [TahfidzAssignmentController::class, 'store']);

    // ---- Keuangan ----
    Route::apiResource('keuangan/tagihan', TagihanController::class);
    Route::post('keuangan/tagihan/generate-monthly', [TagihanController::class, 'generateMonthly']);
    Route::apiResource('keuangan/pembayaran', PembayaranController::class);
    Route::patch('keuangan/pembayaran/{id}/verify', [PembayaranController::class, 'verify']);
    Route::post('keuangan/pembayaran/{id}/proof', [PembayaranController::class, 'uploadProof']);

    // ---- Konten (Berita, Galeri, dll) ----
    Route::apiResource('berita', BeritaController::class);
    Route::apiResource('berita-categories', BeritaCategoryController::class);
    Route::apiResource('galeri', GaleriController::class);
    Route::apiResource('testimoni', TestimoniController::class);
    Route::apiResource('achievement', AchievementController::class);
    Route::apiResource('faq', FAQController::class);
    Route::apiResource('media', MediaController::class);
    Route::post('media/upload', [MediaController::class, 'upload']);

    // ---- Notifikasi ----
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('notifications/stats', [NotificationController::class, 'stats']);

    // ---- Komunikasi ----
    Route::apiResource('announcements', AnnouncementController::class);
    Route::post('announcements/{id}/read', [AnnouncementController::class, 'markRead']);

    // ---- Laporan & Export ----
    Route::get('reports/{type}', [ReportController::class, 'generate']);
    Route::get('export/{type}', [ExportController::class, 'export']);

    // ---- Wali Santri ----
    Route::prefix('wali')->group(function () {
        Route::get('children', [ProgressAnakController::class, 'children']);
        Route::get('children/{santriId}/progress', [ProgressAnakController::class, 'progress']);
        Route::get('children/{santriId}/absensi', [AbsensiAnakController::class, 'index']);
        Route::get('children/{santriId}/tahfidz', [ProgressAnakController::class, 'tahfidz']);
        Route::get('children/{santriId}/jadwal', [ProgressAnakController::class, 'jadwal']);
        Route::get('tagihan', [TagihanAnakController::class, 'index']);
    });

    // ---- Settings ----
    Route::get('site-config', [SiteConfigController::class, 'index']);
    Route::patch('site-config', [SiteConfigController::class, 'update']);
    Route::get('academic-calendar', [AcademicCalendarController::class, 'index']);
    Route::post('academic-calendar', [AcademicCalendarController::class, 'store']);
});
```

---

## 3. Prasyarat & Setup Awal

### Kebutuhan Server

| Komponen | Spesifikasi Minimum |
|---|---|
| OS | Ubuntu 22.04 LTS |
| PHP | 8.3+ (required Laravel 12) |
| Composer | 2.7+ |
| PostgreSQL | 16+ |
| Redis | 7+ |
| Node.js | 20 LTS (untuk frontend) |
| Nginx | Latest |

### Development Lokal (Laragon)

Setup yang sudah berjalan:
- **Apache** port 80 (project-project lain)
- **Nginx** port 8181 (ribath-backend)
- **PostgreSQL 18** port 5432 (standalone install di `C:\Program Files\PostgreSQL\18\`)
- **Redis** port 6379 (aktifkan di Laragon saat dibutuhkan)
- **PHP** 8.2.28 (dengan `pdo_pgsql` dan `pgsql` extension aktif)

### Database Naming Convention

| Environment | Database Name |
|---|---|
| Local | `ribath_app_local` |
| Dev Server | `ribath_app_dev` |
| Production | `ribath_app_prod` |

### Composer Packages yang Dibutuhkan

```bash
# Core
composer require laravel/sanctum            # API Authentication
composer require spatie/laravel-permission   # RBAC (sudah Spatie-compatible!)
composer require laravel/reverb              # WebSocket realtime

# Storage
composer require league/flysystem-aws-s3-v3  # S3/MinIO storage (opsional)

# Utilities
composer require spatie/laravel-query-builder  # Filter, sort, include di API
composer require spatie/laravel-data            # DTO / Data objects
composer require spatie/laravel-medialibrary    # File/image management
composer require maatwebsite/excel              # Import/export Excel
composer require barryvdh/laravel-dompdf        # PDF generation

# Dev
composer require --dev laravel/pint           # Code style fixer
composer require --dev pestphp/pest           # Testing
composer require --dev laravel/telescope      # Debug dashboard
```

---

## Batch 0: Foundation (Minggu 1-3)

> **Goal**: Project Laravel berdiri, database termigrasi, auth + RBAC berfungsi.

### 0.1 Inisialisasi Project

```bash
composer create-project laravel/laravel ribath-backend
cd ribath-backend

# Setup PostgreSQL di .env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ribath_app_local
DB_USERNAME=postgres
DB_PASSWORD=<your_password>

# Install packages
composer require laravel/sanctum spatie/laravel-permission
php artisan install:api        # Sanctum setup
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

### 0.2 Migrasi Database dari Supabase

```bash
# Export dari Supabase
pg_dump --schema-only --no-owner "postgresql://...@db.xxx.supabase.co/postgres" > supabase_schema.sql

# Bersihkan: hapus auth.uid(), RLS policies, supabase-specific
# Lalu import ke PostgreSQL lokal
psql -U postgres -d ribath_app_local < supabase_schema_clean.sql

# Export data
pg_dump --data-only --no-owner "postgresql://...@db.xxx.supabase.co/postgres" > supabase_data.sql
psql -U postgres -d ribath_app_local < supabase_data.sql
```

**PENTING**: Setelah import, buat Laravel migrations untuk setiap tabel agar ke depan semua perubahan schema terkelola via `php artisan migrate`.

### 0.3 Setup Spatie Permission (RBAC)

Karena database sudah punya tabel Spatie-compatible (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`), kita hanya perlu:

1. Konfigurasi `config/permission.php` → arahkan ke tabel yang sudah ada
2. Tambahkan `HasRoles` trait ke model `User`

```php
// app/Models/User.php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles;

    protected $guard_name = 'sanctum';
    // ...
}
```

**Seeder untuk roles & permissions:**

```php
// database/seeders/RolePermissionSeeder.php
// Roles diimplementasi bertahap:
// Prioritas SEKARANG: super_admin, pengurus_pesantren
// Prioritas NANTI: pengurus_pendidikan, pengurus_administrasi, ustadz
// Prioritas MASA DEPAN: wali_santri
$roles = [
    'super_admin',
    'pengurus_pesantren',
    'pengurus_pendidikan',
    'pengurus_administrasi',
    'ustadz',
    'wali_santri',
];

$permissions = [
    // Users
    'view_users', 'create_users', 'edit_users', 'delete_users',
    // Santri
    'view_santri', 'create_santri', 'edit_santri', 'delete_santri', 'view_own_santri',
    // Ustadz
    'view_ustadz', 'create_ustadz', 'edit_ustadz', 'delete_ustadz',
    // Akademik
    'view_nilai', 'create_nilai', 'edit_nilai', 'delete_nilai',
    'view_absensi', 'create_absensi', 'edit_absensi',
    'view_jadwal', 'create_jadwal', 'edit_jadwal', 'delete_jadwal',
    // Tahfidz
    'view_tahfidz', 'create_tahfidz', 'edit_tahfidz',
    // Keuangan
    'view_keuangan', 'create_keuangan', 'edit_keuangan', 'verify_pembayaran',
    // Konten
    'view_berita', 'create_berita', 'edit_berita', 'delete_berita',
    'manage_galeri', 'manage_testimoni', 'manage_faq',
    // PSB
    'view_registrations', 'manage_registrations',
    // Reports
    'view_reports', 'export_data',
    // Settings
    'manage_settings', 'manage_roles',
];
```

### 0.4 Setup Autentikasi (Sanctum)

```php
// LoginController.php
public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => new UserResource($user),
        'roles' => $user->getRoleNames(),
        'permissions' => $user->getAllPermissions()->pluck('name'),
    ]);
}
```

### Deliverable Batch 0

- [x] Project Laravel 12 berjalan (**DONE** — Laravel 12.52.0 terinstall)
- [ ] PostgreSQL terhubung, database `ribath_app_local` dibuat
- [ ] Sanctum auth (login/logout/me) berfungsi
- [ ] Spatie Permission terkonfigurasi dengan roles & permissions
- [ ] Super admin bisa login
- [ ] Basic CORS & API response structure

---

## Batch 1: PSB - Pendaftaran Santri Baru (Minggu 4-6)

> **Goal**: Seluruh alur pendaftaran berjalan end-to-end via Laravel API.
>
> **Kenapa PSB duluan?** PSB adalah fitur **publik-facing** yang paling visible dan merupakan entry point utama calon santri. Alurnya melibatkan: public form → database → admin review → notifikasi, sehingga menguji banyak layer sekaligus.

### Alur Bisnis PSB

```
                    ┌─────────────────┐
                    │  Calon Wali /   │
                    │  Calon Santri   │
                    │  (Publik)       │
                    └────────┬────────┘
                             │
                    ① Quick Interest Form
                    (nama, telepon, program_minat,
                     registrant_type, sumber_info)
                             │
                    ┌────────v────────┐
                    │ Status:         │
                    │ "interest"      │
                    │ + auto-generate │
                    │   PSB-2026-XXX  │
                    │ + completion_   │
                    │   token (UUID)  │
                    └────────┬────────┘
                             │
              ┌──────────────┴──────────────┐
              │                             │
    ② Admin Follow-up                ③ Self-Complete
    (contacted → scheduled_visit     (via completion_token URL)
     → visited)                      /pendaftaran?token=xxx
              │                             │
              └──────────────┬──────────────┘
                             │
                    ④ Full Registration Wizard
                    (data pribadi, keluarga,
                     akademik, dokumen)
                             │
                    ┌────────v────────┐
                    │ Status:         │
                    │ "pending" →     │
                    │ "under_review"  │
                    └────────┬────────┘
                             │
                    ⑤ Admin Review
                    (approve / reject / waitlist)
                             │
                    ┌────────v────────┐
                    │ Status:         │
                    │ "approved" →    │
                    │ Buat data Santri│
                    │ + User account  │
                    │ + Assign role   │
                    └─────────────────┘
```

### Tabel & Model

```
Tabel:
  - calon_santri_registrations   → CalonSantriRegistration model
  - psb_periods                  → PSBPeriod model (kapan pendaftaran buka/tutup)

Relasi:
  - CalonSantriRegistration belongsTo PSBPeriod (opsional)
  - CalonSantriRegistration hasOne Santri (setelah approved)
```

### API Endpoints

```
PUBLIC (tanpa auth):
  POST   /api/public/psb/quick-register           → Simpan quick interest
  GET    /api/public/psb/registration/{token}      → Load data untuk lanjut wizard
  PUT    /api/public/psb/registration/{token}/complete → Submit full wizard
  GET    /api/public/psb-periods                   → List periode PSB aktif

ADMIN (auth + permission: manage_registrations):
  GET    /api/psb/registrations                    → List semua pendaftaran (filter, sort, paginate)
  GET    /api/psb/registrations/{id}               → Detail pendaftaran
  PATCH  /api/psb/registrations/{id}/status        → Update status (contacted, approved, rejected, dll)
  POST   /api/psb/registrations/soft-delete        → Soft delete batch
  CRUD   /api/psb/periods                          → Kelola periode PSB
```

### Laravel Implementation

```php
// app/Models/CalonSantriRegistration.php
class CalonSantriRegistration extends Model
{
    protected $casts = [
        'is_complete' => 'boolean',
        'contacted_at' => 'datetime',
        'visit_scheduled_at' => 'datetime',
    ];

    // Auto-generate registration number
    protected static function booted()
    {
        static::creating(function ($registration) {
            if (!$registration->registration_number) {
                $registration->registration_number = app(RegistrationNumberGenerator::class)->generate();
            }
        });
    }

    // Status: interest → contacted → scheduled_visit → visited → completing → pending → under_review → approved/rejected/waitlist
}

// app/Services/RegistrationNumberGenerator.php
class RegistrationNumberGenerator
{
    public function generate(): string
    {
        $year = now()->format('Y');
        $lastNumber = CalonSantriRegistration::where('registration_number', 'like', "PSB-{$year}-%")
            ->max(DB::raw("CAST(SUBSTRING(registration_number FROM '[0-9]+$') AS INTEGER)"));

        $nextNumber = ($lastNumber ?? 0) + 1;
        return sprintf('PSB-%s-%05d', $year, $nextNumber);
    }
}
```

### Yang Dibuat di Batch Ini

| Komponen | File |
|---|---|
| Model | `CalonSantriRegistration`, `PSBPeriod` |
| Controller | `PublicPSBController`, `RegistrationReviewController`, `PSBPeriodController` |
| Form Request | `QuickRegistrationRequest`, `FullRegistrationRequest` |
| Resource | `RegistrationResource`, `PSBPeriodResource` |
| Service | `RegistrationNumberGenerator` |
| Observer | `RegistrationObserver` (auto-generate nomor) |
| Event | `NewRegistrationEvent` → notifikasi ke admin |
| Migration | Jika perlu adjustment dari schema Supabase |
| Test | Feature test untuk semua endpoint PSB |

### Deliverable Batch 1

- [x] Quick registration publik berfungsi
- [x] Nomor pendaftaran auto-generate (PSB-2026-XXXXX)
- [x] Admin bisa review, filter, update status pendaftaran
- [x] Completion token berfungsi untuk lanjut wizard
- [x] Periode PSB CRUD berfungsi
- [x] Event notification saat pendaftaran baru masuk

---

## Batch 2: Manajemen User & Santri (Minggu 7-9)

> **Goal**: Admin bisa kelola user accounts dan data santri. Pendaftaran yang approved bisa dikonversi jadi santri + user account.

### Alur Bisnis

```
PSB Approved → Buat record Santri → Buat User account (wali) → Assign role wali_santri
                                   → Link santri ke wali

Manual:      → Admin buat Santri langsung (tanpa PSB)
             → Admin buat User account (ustadz, khodim, dll) + assign role
```

### Tabel & Model

```
Tabel:
  - users / profiles         → User model (auth + profile info)
  - santri                   → Santri model (data lengkap santri)
  - kontak_wali_santri       → KontakWaliSantri (info kontak wali)

Relasi:
  - User hasMany Santri (sebagai wali)
  - Santri belongsTo User (wali_santri_id)
  - Santri belongsTo TahunAjaran
  - CalonSantriRegistration hasOne Santri (post-approval)
```

### API Endpoints

```
ADMIN:
  CRUD   /api/users                              → Kelola user accounts
  POST   /api/users/{id}/send-credentials        → Kirim credential via email/WA
  PATCH  /api/users/{id}/password                 → Reset password
  GET    /api/roles-permissions                   → List roles & permissions
  POST   /api/roles-permissions/assign            → Assign role ke user

  CRUD   /api/santri                              → Kelola data santri
  GET    /api/santri/{id}/detail                  → Detail santri (relasi lengkap)
  POST   /api/santri/bulk-import                  → Import santri dari Excel

  POST   /api/psb/registrations/{id}/convert      → Konversi approved → santri + user
```

### Yang Dibuat di Batch Ini

| Komponen | File |
|---|---|
| Model | `User` (update), `Santri`, `KontakWaliSantri` |
| Controller | `UserController`, `SantriController`, `RolePermissionController` |
| Form Request | `StoreUserRequest`, `StoreSantriRequest` |
| Resource | `UserResource`, `SantriResource`, `SantriDetailResource` |
| Permission | Spatie permission middleware pada routes |
| Job | `SendCredentialsToUser` (kirim email/WA) |
| Command | `php artisan admin:bootstrap` (buat super admin pertama) |
| Test | Feature test user CRUD, santri CRUD, role assignment |

### Deliverable Batch 2

- [x] CRUD User dengan role assignment
- [x] CRUD Santri dengan relasi wali
- [x] Konversi pendaftaran approved → santri + user
- [x] Bulk import santri dari Excel
- [x] Send credentials ke user baru
- [x] Spatie permission middleware per-resource

---

## Batch 3: Manajemen Ustadz & SDM (Minggu 10-11)

> **Goal**: Data ustadz dan khodim terkelola, bisa di-assign ke jadwal dan tugas.

### Tabel & Model

```
  - ustadz           → Ustadz model (kode_ustadz, spesialisasi, mata_pelajaran, dll)
  - khodim            → Khodim model (area_tanggung_jawab, shift, supervisor)
  - pengurus          → Pengurus model (jabatan, periode)
```

### API Endpoints

```
  CRUD   /api/ustadz                  → Kelola data ustadz
  POST   /api/ustadz/bulk-import      → Import ustadz dari Excel
  POST   /api/ustadz/replace-tahfidz  → Ganti ustadz tahfidz

  CRUD   /api/khodim                  → Kelola data khodim
  CRUD   /api/pengurus                → Kelola data pengurus
```

### Deliverable Batch 3

- [x] CRUD Ustadz (dengan spesialisasi & mata pelajaran)
- [x] CRUD Khodim (dengan assignment area & shift)
- [x] Bulk import ustadz
- [x] Ustadz replacement mechanism

---

## Batch 4: Kurikulum & Penjadwalan (Minggu 12-14)

> **Goal**: Master data kurikulum dan jadwal mengajar berfungsi.

### Tabel & Model

```
  - fann_mata_pelajaran / fann_categories  → FannCategory model
  - kitab                                  → Kitab model
  - mata_pelajaran                         → MataPelajaran model
  - slot_waktu                             → SlotWaktu model
  - jadwal_mengajar                        → JadwalMengajar model
  - tahun_ajaran                           → TahunAjaran model
  - academic_calendar                      → AcademicCalendar model
```

### API Endpoints

```
Master Data:
  CRUD   /api/tahun-ajaran           → Tahun ajaran
  CRUD   /api/fann-categories        → Kategori fann (mata pelajaran)
  CRUD   /api/kitab                  → Kitab
  CRUD   /api/slot-waktu             → Slot waktu mengajar

Jadwal:
  CRUD   /api/jadwal                 → Jadwal mengajar
  GET    /api/jadwal/conflicts       → Cek konflik jadwal (view)
  POST   /api/jadwal/bulk-import     → Import jadwal dari Excel

Kalender:
  CRUD   /api/academic-calendar      → Kalender akademik
```

### Deliverable Batch 4

- [x] CRUD semua master data kurikulum
- [x] CRUD Jadwal mengajar dengan validasi konflik
- [x] Jadwal conflict detection
- [x] Bulk import jadwal
- [x] Kalender akademik CRUD

---

## Batch 5: Absensi & Presensi (Minggu 15-16)

> **Goal**: Input absensi harian & kegiatan, rekap bulanan.

### Tabel & Model

```
  - absensi_harian          → AbsensiHarian model
  - absensi_kegiatan        → AbsensiKegiatan model
  - absensi_rekap_bulanan   → AbsensiRekapBulanan model
```

### API Endpoints

```
  CRUD   /api/absensi/harian                  → Absensi harian per santri
  CRUD   /api/absensi/kegiatan                → Absensi kegiatan (shalat, dll)
  GET    /api/absensi/rekap-bulanan           → Rekap bulanan
  POST   /api/absensi/bulk                    → Input absensi massal (satu kelas)
```

### Deliverable Batch 5

- [x] Input absensi per santri dan per kelas (bulk)
- [x] Absensi kegiatan (shalat, kajian, dll)
- [x] Rekap bulanan otomatis
- [x] Filter by tanggal, kelas, status

---

## Batch 6: Penilaian & Evaluasi Akademik (Minggu 17-19)

> **Goal**: Ustadz bisa input nilai, admin bisa lihat rekap & evaluasi.

### Tabel & Model

```
  - nilai_santri         → NilaiSantri model (harian/uts/uas/tugas/praktik/hafalan)
  - evaluasi_santri      → EvaluasiSantri model (evaluasi naratif per aspek)
  - evaluasi_bulanan     → EvaluasiBulanan model
  - report_card_settings → ReportCardSetting model
```

### API Endpoints

```
  CRUD   /api/nilai                            → Nilai santri
  POST   /api/nilai/bulk                       → Input nilai massal
  GET    /api/nilai/bulanan                    → Rekap nilai bulanan
  CRUD   /api/evaluasi                         → Evaluasi naratif
  GET    /api/evaluasi/bulanan                 → Evaluasi bulanan
```

### Deliverable Batch 6

- [x] CRUD Nilai (semua jenis: harian, UTS, UAS, tugas, praktik, hafalan)
- [x] Bulk input nilai per kelas
- [x] Evaluasi naratif per santri
- [x] Rekap bulanan

---

## Batch 7: Tahfidz (Hafalan Al-Quran) (Minggu 20-22)

> **Goal**: Sistem tracking hafalan Quran lengkap.

### Tabel & Model

```
  - tahfidz_program       → TahfidzProgram model
  - tahfidz_enrollments   → TahfidzEnrollment model
  - tahfidz_juz_progress  → TahfidzJuzProgress model (per juz, status, kualitas)
  - tahfidz_setoran       → TahfidzSetoran model (harian)
  - tahfidz_hizib_schedule → TahfidzHizibSchedule model
```

### API Endpoints

```
  CRUD   /api/tahfidz/program                   → Program tahfidz
  CRUD   /api/tahfidz/setoran                   → Setoran hafalan harian
  CRUD   /api/tahfidz/juz-progress               → Progress per juz
  GET/POST /api/tahfidz/assignments              → Assignment ustadz-santri
  GET    /api/tahfidz/analytics                  → Statistik tahfidz
  GET    /api/tahfidz/bulanan                    → Rekap bulanan tahfidz
```

### Scheduled Jobs (Pengganti Edge Functions)

```php
// app/Console/Kernel.php (atau routes/console.php di Laravel 12)
Schedule::job(new SendTahfidzDailyReport)->dailyAt('20:00');
Schedule::job(new SendTahfidzWeeklySummary)->weeklyOn(5, '20:00'); // Jumat
Schedule::job(new AutoScheduleTahfidzUjian)->monthlyOn(1, '08:00');
```

### Deliverable Batch 7

- [x] Program tahfidz CRUD
- [x] Setoran harian (input & verifikasi)
- [x] Progress per juz (30 juz tracking)
- [x] Assignment ustadz-santri
- [x] Scheduled jobs: daily report, weekly summary
- [x] Analytics tahfidz

---

## Batch 8: Keuangan & Pembayaran (Minggu 23-26)

> **Goal**: Sistem keuangan end-to-end: tagihan → pembayaran → verifikasi → laporan.

### Tabel & Model

```
  - jenis_tagihan        → JenisTagihan model (SPP, uang masuk, uang kitab, dll)
  - tagihan_santri       → TagihanSantri model
  - pembayaran           → Pembayaran model
  - payment_proofs       → PaymentProof model (bukti bayar)
  - finance_pengeluaran  → Pengeluaran model
  - financial_audit_log  → FinancialAuditLog model
```

### API Endpoints

```
  CRUD   /api/keuangan/jenis-tagihan                → Jenis tagihan
  CRUD   /api/keuangan/tagihan                      → Tagihan santri
  POST   /api/keuangan/tagihan/generate-monthly     → Generate tagihan SPP bulanan
  CRUD   /api/keuangan/pembayaran                   → Record pembayaran
  PATCH  /api/keuangan/pembayaran/{id}/verify        → Verifikasi pembayaran
  POST   /api/keuangan/pembayaran/{id}/proof          → Upload bukti bayar
  CRUD   /api/keuangan/pengeluaran                   → Pengeluaran
  GET    /api/keuangan/laporan/ringkasan             → Laporan ringkasan keuangan
```

### Scheduled Jobs

```php
// Generate tagihan SPP setiap tanggal 1
Schedule::job(new GenerateMonthlyInvoices)->monthlyOn(1, '00:00');
```

### Deliverable Batch 8

- [x] Jenis tagihan CRUD (SPP, uang masuk, dll)
- [x] Generate tagihan bulanan otomatis (cron)
- [x] Input pembayaran + upload bukti bayar
- [x] Verifikasi pembayaran oleh admin
- [x] Pengeluaran CRUD
- [x] Audit log keuangan
- [x] Laporan keuangan

---

## Batch 9: Landing Page & Konten Publik (Minggu 27-29)

> **Goal**: Semua konten publik (berita, galeri, testimoni, prestasi, FAQ) dikelola via Laravel.

### Tabel & Model

```
  - berita_articles      → BeritaArticle model
  - berita_categories    → BeritaCategory model
  - galeri_items         → GaleriItem model
  - testimoni_entries    → TestimoniEntry model
  - achievement_entries  → AchievementEntry model
  - faq_entries          → FAQEntry model
  - media_library        → MediaLibrary model
  - landing_sections     → LandingSection model
  - contact_info         → ContactInfo model
```

### API Endpoints

```
PUBLIC (tanpa auth):
  GET    /api/public/landing          → Semua data landing page (sections, berita, dll)
  GET    /api/public/berita           → List berita
  GET    /api/public/berita/{slug}    → Detail berita
  GET    /api/public/galeri           → Galeri
  GET    /api/public/testimoni        → Testimoni
  GET    /api/public/achievement      → Prestasi
  GET    /api/public/faq              → FAQ
  GET    /api/public/contact          → Info kontak

ADMIN (auth):
  CRUD   /api/berita                  → Kelola berita
  CRUD   /api/berita-categories       → Kelola kategori
  CRUD   /api/galeri                  → Kelola galeri
  CRUD   /api/testimoni               → Kelola testimoni (drag-drop ordering)
  CRUD   /api/achievement             → Kelola prestasi
  CRUD   /api/faq                     → Kelola FAQ
  CRUD   /api/media                   → Media library
  POST   /api/media/upload            → Upload gambar
```

### File Storage Setup

```php
// config/filesystems.php
'disks' => [
    'public' => [
        'driver' => 'local',
        'root' => storage_path('app/public'),
        'url' => env('APP_URL') . '/storage',
        'visibility' => 'public',
    ],
    // Atau MinIO untuk production:
    'minio' => [
        'driver' => 's3',
        'key' => env('MINIO_KEY'),
        'secret' => env('MINIO_SECRET'),
        'region' => 'us-east-1',
        'bucket' => env('MINIO_BUCKET'),
        'endpoint' => env('MINIO_ENDPOINT'),
        'use_path_style_endpoint' => true,
    ],
],
```

### Deliverable Batch 9

- [x] Semua konten publik di-serve via Laravel API
- [x] Admin CRUD untuk semua konten
- [x] File upload (gambar berita, galeri, foto profil) via Laravel Storage
- [x] Image optimization (via Spatie MediaLibrary atau Intervention Image)
- [x] Migrasi file dari Supabase Storage

---

## Batch 10: Notifikasi, Realtime & Komunikasi (Minggu 30-32)

> **Goal**: Notifikasi in-app, push, broadcasting realtime.

### Tabel & Model

```
  - notifications              → Notification model (custom, bukan bawaan Laravel)
  - notification_preferences   → NotificationPreference model
  - announcements              → Announcement model
  - announcement_reads         → AnnouncementRead model
```

### Realtime dengan Laravel Reverb

```php
// app/Events/NewNotificationEvent.php
class NewNotificationEvent implements ShouldBroadcast
{
    public function __construct(
        public Notification $notification
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('notifications.' . $this->notification->user_id);
    }
}

// routes/channels.php
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return $user->id === $userId;
});
```

### Scheduled Jobs

```php
Schedule::job(new CleanupExpiredNotifications)->dailyAt('02:00');
Schedule::job(new FetchPrayerTimes)->dailyAt('00:01');
```

### Deliverable Batch 10

- [x] Notifikasi in-app (database + API)
- [x] Laravel Reverb untuk realtime push
- [x] Pengumuman CRUD + tandai sudah dibaca
- [x] Notification preferences per user
- [x] Cleanup expired notifications (cron)

---

## Batch 11: Laporan, Analitik & Export (Minggu 33-34)

> **Goal**: Dashboard stats, laporan, export PDF/Excel.

### API Endpoints

```
  GET    /api/dashboard/stats                  → Dashboard statistics
  GET    /api/reports/{type}                   → Generate laporan (akademik, keuangan, santri, kinerja)
  GET    /api/export/{type}                    → Export PDF/Excel
  GET    /api/analytics/query                  → Custom analytics query (admin)
```

### Packages

```php
// PDF: barryvdh/laravel-dompdf
// Excel: maatwebsite/excel
```

### Deliverable Batch 11

- [x] Dashboard statistics API
- [x] Laporan akademik, keuangan, kehadiran, kinerja
- [x] Export PDF (rapor, laporan keuangan)
- [x] Export Excel (data santri, nilai, absensi)

---

## Batch 12: Portal Wali Santri (Minggu 35-36)

> **Goal**: Wali santri bisa melihat progress anak, tagihan, absensi, jadwal.

### API Endpoints

```
  GET    /api/wali/children                     → List anak yang terdaftar
  GET    /api/wali/children/{santriId}/progress  → Progress akademik anak
  GET    /api/wali/children/{santriId}/absensi   → Rekap absensi anak
  GET    /api/wali/children/{santriId}/tahfidz   → Progress hafalan anak
  GET    /api/wali/children/{santriId}/jadwal    → Jadwal anak
  GET    /api/wali/tagihan                       → Tagihan & riwayat pembayaran
```

### Authorization

```php
// Menggunakan Spatie Permission + custom check di Service/Controller
// TIDAK menggunakan Laravel Policy

// Contoh di controller:
public function progress(Request $request, string $santriId)
{
    $santri = Santri::findOrFail($santriId);

    // Wali hanya bisa lihat data anaknya sendiri
    if ($request->user()->hasRole('wali_santri') && $santri->wali_santri_id !== $request->user()->id) {
        abort(403, 'Anda hanya bisa melihat data anak sendiri.');
    }

    return new SantriProgressResource($santri);
}
```

### Deliverable Batch 12

- [x] Portal wali: lihat semua data anak
- [x] Tagihan & riwayat pembayaran
- [x] Data hanya bisa diakses oleh wali yang bersangkutan (Spatie permission + ownership check)

---

## Batch 13: Migrasi Frontend (Paralel dengan Setiap Batch)

> **PENTING**: Migrasi frontend dilakukan **paralel** dengan setiap batch backend. Setiap kali satu batch backend selesai, frontend untuk fitur tersebut langsung dimigrasikan.

### Strategi: API Client Adapter

```typescript
// src/lib/apiClient.ts (menggantikan supabase client)
import axios from 'axios';

const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8181/api',
  headers: { 'Accept': 'application/json' },
});

// Auto-attach Sanctum token
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Auto-refresh / redirect on 401
apiClient.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }
    return Promise.reject(err);
  }
);

export default apiClient;
```

### Urutan Migrasi Frontend per Batch

| Batch Backend | Frontend yang Dimigrasikan |
|---|---|
| Batch 0 (Auth) | `AuthContext.tsx`, `LoginForm`, `SignupForm`, `authService.ts` |
| Batch 1 (PSB) | `/pendaftaran/*`, `/psb`, `/admin/pendaftaran-*`, `quickRegistrationService.ts` |
| Batch 2 (User+Santri) | `/users`, `/santri`, `/admin/user-sync`, `/admin/bulk/santri` |
| Batch 3 (Ustadz) | `/ustadz`, `/admin/bulk/ustadz` |
| Batch 4 (Kurikulum) | `/kitab`, `/jadwal`, `/master-data`, `/admin/tahun-ajaran`, `/admin/kalender-akademik` |
| Batch 5 (Absensi) | `/absensi/*`, `backgroundSyncService.ts` (sync target ke API) |
| Batch 6 (Nilai) | `/nilai`, `/evaluasi`, `/nilai/bulanan` |
| Batch 7 (Tahfidz) | `/tahfidz/*`, `/admin/tahfidz/*` |
| Batch 8 (Keuangan) | `/keuangan`, `/tagihan` |
| Batch 9 (Konten) | `/berita`, `/galeri`, `/admin/landing-*`, `mediaLibraryService.ts`, `photoUploadService.ts` |
| Batch 10 (Notifikasi) | `realtimeService.ts` → Socket.IO/Reverb, `notificationService.ts` |
| Batch 11 (Laporan) | `/laporan`, `/analytics`, `/admin/reports` |
| Batch 12 (Wali) | `/progress-anak`, `/tagihan` (wali), `/absensi-anak`, `/tahfidz-anak`, `/jadwal-anak` |

### Pola Migrasi Hook

```typescript
// SEBELUM (Supabase)
const { data } = await supabase.from('santri').select('*').eq('status', 'aktif');

// SESUDAH (Laravel API via apiClient)
const { data } = await apiClient.get('/santri', { params: { status: 'aktif' } });
```

---

## Batch 14: Go-Live & Cutover (Minggu 37-40)

### 14.1 Pre-Go-Live Checklist

**Testing:**
- [ ] Semua endpoint punya feature test (minimal happy path + error case)
- [ ] E2E test via Playwright untuk flow kritis (login, PSB, nilai, keuangan)
- [ ] Load test (100 concurrent users minimum)
- [ ] Security audit (OWASP top 10, SQL injection, XSS)

**Infrastructure:**
- [ ] Server production ready (Nginx + PHP-FPM + PostgreSQL + Redis)
- [ ] SSL certificate aktif
- [ ] Laravel queue worker berjalan (Supervisor)
- [ ] Laravel Reverb berjalan
- [ ] Scheduled tasks berjalan (cron)
- [ ] Backup database otomatis (daily)
- [ ] Monitoring: Uptime Kuma + Laravel Telescope + Sentry

**Data Migration:**
- [ ] Final data sync dari Supabase
- [ ] File storage migrasi selesai
- [ ] URL references di database sudah diupdate
- [ ] Password hash compatible (bcrypt — sama di Supabase dan Laravel)

### 14.2 Strategi Cutover

```
Rekomendasi: Gradual Migration (per modul)

Minggu 37: Landing page + PSB → Laravel API
Minggu 38: Auth + User management + Santri/Ustadz → Laravel API
Minggu 39: Akademik (jadwal, nilai, absensi, tahfidz) → Laravel API
Minggu 40: Keuangan + Laporan + Notifikasi → Laravel API
           → Matikan Supabase setelah 2 minggu stable
```

### 14.3 Deployment Stack

```
Server Production:
  ├── Nginx (reverse proxy + SSL + static files)
  ├── PHP 8.3 + PHP-FPM
  ├── Laravel Application
  │   ├── Queue Worker (Supervisor, 2-4 processes)
  │   ├── Scheduler (cron: * * * * * php artisan schedule:run)
  │   └── Reverb WebSocket Server
  ├── PostgreSQL 16
  ├── Redis 7
  └── MinIO (atau local storage + Nginx serve)
```

---

## Ringkasan Timeline

| Batch | Minggu | Durasi | Fitur |
|---|---|---|---|
| **0** | 1-3 | 3 minggu | Foundation (Laravel, DB, Auth, RBAC) |
| **1** | 4-6 | 3 minggu | PSB (Pendaftaran Santri Baru) |
| **2** | 7-9 | 3 minggu | User Management & Santri |
| **3** | 10-11 | 2 minggu | Ustadz & SDM |
| **4** | 12-14 | 3 minggu | Kurikulum & Penjadwalan |
| **5** | 15-16 | 2 minggu | Absensi & Presensi |
| **6** | 17-19 | 3 minggu | Penilaian & Evaluasi |
| **7** | 20-22 | 3 minggu | Tahfidz |
| **8** | 23-26 | 4 minggu | Keuangan & Pembayaran |
| **9** | 27-29 | 3 minggu | Landing Page & Konten |
| **10** | 30-32 | 3 minggu | Notifikasi & Realtime |
| **11** | 33-34 | 2 minggu | Laporan & Export |
| **12** | 35-36 | 2 minggu | Portal Wali Santri |
| **13** | paralel | - | Migrasi Frontend (seiring setiap batch) |
| **14** | 37-40 | 4 minggu | Go-Live & Cutover |
| | | **~40 minggu** | **~10 bulan** (1 developer) |

> **Dengan 2 developer** (1 backend + 1 frontend paralel): estimasi bisa dipercepat menjadi **~6-7 bulan**.

---

## Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Data loss saat migrasi DB | Kritis | Backup berlapis, test di staging, verify checksum |
| Password hash incompatible | Tinggi | Supabase dan Laravel sama-sama pakai bcrypt — kompatibel! |
| Downtime saat cutover | Tinggi | Gradual migration per modul, parallel running |
| Realtime tidak stabil | Sedang | Laravel Reverb + Redis, fallback ke polling |
| File storage kehilangan file | Tinggi | Checksum verification setelah migrasi |
| Spatie Permission config salah | Sedang | Gunakan tabel yang sudah ada, test RBAC menyeluruh |
| Laravel 12 breaking changes | Rendah | Gunakan stable release, cek upgrade guide |
| Biaya server lebih tinggi | Rendah | VPS mulai Rp 100-300rb/bulan, hitung TCO dulu |

---

## Catatan Penting

1. **Spatie Permission saja, tanpa Laravel Policy** — Semua otorisasi menggunakan Spatie Permission middleware dan method (`hasPermissionTo`, `hasRole`). Permission disimpan di database, bukan hardcoded di Policy files. Ini menghindari duplikasi logic antara Spatie dan Policy.

2. **Spatie Permission sudah compatible** — Database Supabase sudah punya tabel `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` yang sesuai format Spatie.

3. **Password hash compatible** — Supabase Auth dan Laravel keduanya menggunakan bcrypt. User bisa login dengan password yang sama tanpa reset.

4. **Laravel Reverb untuk broadcasting** — Reverb adalah WebSocket server bawaan Laravel (self-hosted, gratis). Digunakan untuk live notification ke React web app dan future mobile app. Tidak perlu Pusher atau layanan pihak ketiga.

5. **Mobile App Readiness** — API dirancang untuk multi-client (web + mobile). Gunakan Sanctum token (stateless), pagination di semua list endpoint, response format konsisten, dan offline-friendly patterns.

6. **Role diimplementasi bertahap** — Saat ini hanya `super_admin` dan `pengurus_pesantren` yang aktif. Role lain (`pengurus_pendidikan`, `pengurus_administrasi`, `ustadz`, `wali_santri`) dibuat di database tapi fitur-fiturnya dibangun bertahap sesuai batch.

7. **Jangan hapus Supabase sebelum stable** — Pertahankan Supabase minimal 1 bulan setelah semua modul live di Laravel.

8. **Feature flag** — Pertimbangkan pakai feature flag di frontend agar bisa switch antara Supabase dan Laravel per modul selama masa transisi.

9. **Aplikasi generic** — Dirancang agar bisa dipakai oleh pesantren manapun, bukan hanya satu pesantren tertentu.

---

> **Dokumen ini adalah living document.** Update sesuai progress setiap batch.
>
> Terakhir diupdate: 23 Februari 2026
