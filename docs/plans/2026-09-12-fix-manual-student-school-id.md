# Fix: "Set Skema Biaya" gagal untuk santri lama — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Santri yang ditambahkan manual (form "Tambah Santri", bukan lewat PSB) bisa di-set skema biayanya, dan 14 santri lama yang sudah terlanjur rusak di production ikut diperbaiki.

**Architecture:** Root cause-nya satu: `StudentService::createStudent()` tidak pernah mengisi `students.school_id`, jadi santri manual tersimpan dengan `school_id = NULL` dan ditolak 404 oleh `EnsuresActiveSchoolTenancy` di semua endpoint keuangan. Perbaikan = (1) service mengisi `school_id` dari sekolah aktif di server-side, sama seperti `PsbService` mengisinya dari registrasi; (2) migration data sekali jalan untuk mengisi baris yang sudah NULL. Frontend tidak perlu diubah.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16 (prod) / SQLite in-memory (tests), Pest.

**Spec / referensi:**
- Hasil investigasi (ringkas di bawah, bagian "Root cause").
- Desain fee management: `ribath-masjid-hub/docs/superpowers/specs/2026-05-19-fee-management-design.md` (FR-014 — set skema untuk santri lama / legacy).
- Konvensi backend: `CLAUDE.md` di repo ini.

## Global Constraints

- Tenancy `school_id` selalu diisi **server-side**, tidak pernah diterima dari request client.
- Otorisasi hanya lewat Spatie Permission — jangan menambah Policy.
- Nama variabel/method deskriptif (lihat CLAUDE.md "Prioritize clarity over brevity").
- Semua perubahan wajib punya test Pest; test berjalan di SQLite in-memory, jadi migration harus kompatibel SQLite **dan** PostgreSQL (pakai query builder, bukan SQL khusus Postgres).
- Jangan jalankan `migrate:fresh` / `db:wipe` di lokal (diblokir `DB_ALLOW_DESTRUCTIVE`); cukup `php artisan migrate`.
- Jangan deploy ke VPS tanpa instruksi eksplisit dari user.

## Root cause (hasil investigasi 2026-09-12, data = salinan prod)

| Bukti | Temuan |
|---|---|
| Data | 14 santri tanpa `registration_id` (input manual 25–26 Jul 2026) semuanya `school_id = NULL` dan 0 skema biaya. 7 santri PSB: `school_id` terisi. Tabel lain: 0 baris NULL. |
| API | `GET` & `POST .../fee-assignments/snapshot` untuk santri lama → **404** dari `app/Traits/EnsuresActiveSchoolTenancy.php:18`. Santri PSB → 200. |
| UI | Tab Biaya menampilkan "Belum ada skema biaya" (GET 404 tertelan); klik Simpan → toast "Gagal menyimpan — Not Found", modal tetap terbuka. Banner di /santri bilang "6 santri belum memiliki skema biaya" padahal 20 — 14 santri lama tidak terhitung (`unassignedStudentsQuery` filter `school_id`). |
| Kode | `StoreStudentRequest` tidak punya `school_id`; `StudentService::createStudent()` = `Student::create($data)` apa adanya; model `Student` tidak punya hook. `PsbService::acceptRegistration()` mengisi `'school_id' => $registration->school_id` — itu sebabnya santri PSB aman. |
| Hipotesis diuji | Isi `school_id` 1 santri lama → GET 200, snapshot 201 (SPP Bulanan Rp1.000.000). Di-revert setelahnya. |
| Kenapa test tidak menangkap | `StudentFactory` tidak mengisi `school_id`, dan test "legacy student" di `StudentFeeAssignmentTest` mengisi `school_id` manual — tidak ada test yang membuat santri lewat `POST /students` lalu set skema. |
| Kenapa belum ter-backfill | `SchoolSeeder` memang mem-backfill `students.school_id` NULL, tapi hanya jalan saat `deploy.sh --seed`. Deploy terakhir 30 Jun, santri diinput 25–26 Jul. |

Endpoint yang ikut rusak untuk santri `school_id = NULL` (semua pakai `ensureBelongsToActiveSchool`): fee assignments, fee exceptions, bills, student payments.

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `app/Services/StudentService.php` | Modify `createStudent()` | Mengisi `school_id` sekolah aktif sebelum `Student::create` |
| `tests/Feature/Admin/StudentManagementTest.php` | Add test | Santri manual mendapat `school_id` sekolah aktif |
| `tests/Feature/Keuangan/StudentFeeAssignmentTest.php` | Add test | Regresi end-to-end: `POST /students` → snapshot skema biaya 201 |
| `database/migrations/2026_09_12_000000_backfill_students_school_id.php` | Create | Backfill sekali jalan untuk baris `school_id` NULL |
| `tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php` | Create | Test perilaku migration backfill |

---

### Task 1: Santri manual mendapat `school_id` sekolah aktif

**Files:**
- Modify: `app/Services/StudentService.php` (method `createStudent`, sekitar baris 44–50; import `App\Models\School`)
- Test: `tests/Feature/Admin/StudentManagementTest.php`
- Test: `tests/Feature/Keuangan/StudentFeeAssignmentTest.php`

**Interfaces:**
- Consumes: `School::activeOrFail(): School` (sudah ada di `app/Models/School.php:46`, melempar `RuntimeException` jika tidak ada sekolah aktif).
- Produces: `StudentService::createStudent(array $data): Student` — signature tidak berubah; kini selalu mengisi `school_id`.

- [ ] **Step 0: Buat branch**

```bash
cd ~/Projects/Dev/Web/ribath-backend
git checkout main && git pull
git checkout -b fix/manual-student-school-id
```

- [ ] **Step 1: Tulis test unit-level yang gagal (StudentManagementTest)**

Tambahkan `use App\Models\School;` di bagian import, lalu tambahkan test ini tepat setelah test `'create student manually'`:

```php
test('create student manually assigns the active school', function () {
    $admin = createStudentAdmin();
    $activeSchool = School::where('is_active', true)->firstOrFail();

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'Santri Lama',
            'birth_date' => '2012-03-10',
            'gender' => 'L',
            'program' => 'regular',
            'entry_date' => '2025-07-01',
        ])
        ->assertStatus(201);

    $createdStudent = Student::findOrFail($response->json('data.id'));
    expect($createdStudent->school_id)->toBe($activeSchool->id);
});
```

- [ ] **Step 2: Tulis test regresi end-to-end yang gagal (StudentFeeAssignmentTest)**

Tambahkan di bagian "manual snapshot" (setelah test `'manager can manual-snapshot a legacy student with AY referensi'`). Test ini sengaja membuat santri lewat endpoint yang sama dengan form "Tambah Santri", bukan lewat factory — itu celah yang membuat bug lolos.

```php
test('student created via manual Tambah Santri form can get a fee snapshot', function () {
    Permission::firstOrCreate(['name' => 'create-students']);
    $admin = makeStudentFeeManager();
    $admin->givePermissionTo('create-students');

    $manuallyCreatedStudentId = $this->actingAs($admin)
        ->postJson('/api/v1/students', [
            'full_name' => 'Santri Lama',
            'birth_date' => '2012-03-10',
            'gender' => 'L',
            'program' => 'regular',
            'entry_date' => '2025-07-01',
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($admin)
        ->getJson("/api/v1/students/{$manuallyCreatedStudentId}/fee-assignments")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/students/{$manuallyCreatedStudentId}/fee-assignments/snapshot", [
            'academic_year_id' => $this->activeAy->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.skipped_count', 0);
});
```

- [ ] **Step 3: Jalankan kedua test, pastikan GAGAL karena bug**

Run:
```bash
./vendor/bin/pest tests/Feature/Admin/StudentManagementTest.php --filter="assigns the active school"
./vendor/bin/pest tests/Feature/Keuangan/StudentFeeAssignmentTest.php --filter="Tambah Santri"
```
Expected:
- Test pertama FAIL: `Failed asserting that null is identical to '<uuid sekolah>'`.
- Test kedua FAIL di `getJson(...)->assertOk()`: `Expected response status code [200] but received 404.`

- [ ] **Step 4: Implementasi minimal di `StudentService::createStudent()`**

Tambahkan `use App\Models\School;` di import, lalu ubah method menjadi:

```php
    public function createStudent(array $data): Student
    {
        // Manual "Tambah Santri" path. Tenancy is assigned server-side, the same
        // way PsbService takes it from the registration. Without it the student
        // is saved with school_id NULL and every tenancy-guarded endpoint (fee
        // assignments, bills, payments) returns 404 for them.
        $data['school_id'] = School::activeOrFail()->id;

        $student = Student::create($data);
        $this->syncProfileCompletionTimestamp($student);

        return $student->load(['guardian', 'registration']);
    }
```

- [ ] **Step 5: Jalankan kedua test, pastikan LULUS**

Run:
```bash
./vendor/bin/pest tests/Feature/Admin/StudentManagementTest.php --filter="assigns the active school"
./vendor/bin/pest tests/Feature/Keuangan/StudentFeeAssignmentTest.php --filter="Tambah Santri"
```
Expected: PASS keduanya.

- [ ] **Step 6: Jalankan seluruh test suite — pastikan tidak ada regresi**

Run: `./vendor/bin/pest`
Expected: semua PASS. Perhatikan khusus test di `tests/Feature/Admin/` dan `tests/Feature/StudentProfileCompletenessTest.php` yang memanggil `POST /api/v1/students`: semuanya men-seed `SchoolSeeder` (ada sekolah aktif). Jika ada test yang membuat santri tanpa sekolah aktif dan kini gagal dengan `No active school found`, tambahkan `$this->seed(\Database\Seeders\SchoolSeeder::class);` di `beforeEach` file tersebut — itu memang prasyarat yang benar.

- [ ] **Step 7: Format & commit**

```bash
./vendor/bin/pint app/Services/StudentService.php tests/Feature/Admin/StudentManagementTest.php tests/Feature/Keuangan/StudentFeeAssignmentTest.php
git add app/Services/StudentService.php tests/Feature/Admin/StudentManagementTest.php tests/Feature/Keuangan/StudentFeeAssignmentTest.php
git commit -m "fix(students): assign active school to manually created students

Students added via the Tambah Santri form were saved with school_id NULL,
so every tenancy-guarded fee endpoint returned 404 and admins could not
set a fee scheme for them.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Backfill `school_id` untuk santri yang sudah terlanjur NULL

**Files:**
- Create: `database/migrations/2026_09_12_000000_backfill_students_school_id.php`
- Test: `tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php` (folder baru; sudah tercakup `pest()->in('Feature')` di `tests/Pest.php`)

**Interfaces:**
- Consumes: tabel `schools` (`id`, `is_active`), `students` (`school_id`).
- Produces: tidak ada API baru. Migration mengembalikan anonymous class dengan `up()` / `down()` — test memanggil `up()` langsung via `require`.

Kenapa migration, bukan menjalankan `SchoolSeeder`: `scripts/deploy.sh` selalu menjalankan `artisan migrate --force` (dengan backup pre-migrate), sedangkan seeder hanya jalan dengan `--seed`. Migration menjamin backfill terjadi tepat sekali di setiap environment.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php`:

```php
<?php

use App\Models\School;
use App\Models\Student;

function runBackfillStudentsSchoolIdMigration(): void
{
    (require database_path('migrations/2026_09_12_000000_backfill_students_school_id.php'))->up();
}

test('backfill assigns the active school to students without school_id', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $inactiveSchool = School::factory()->create(['is_active' => false]);
    $studentWithoutSchool = Student::factory()->create(['school_id' => null]);
    $studentOfInactiveSchool = Student::factory()->create(['school_id' => $inactiveSchool->id]);

    runBackfillStudentsSchoolIdMigration();

    expect($studentWithoutSchool->fresh()->school_id)->toBe($activeSchool->id)
        ->and($studentOfInactiveSchool->fresh()->school_id)->toBe($inactiveSchool->id);
});

test('backfill also fixes soft-deleted students so a restore works', function () {
    $activeSchool = School::factory()->create(['is_active' => true]);
    $softDeletedStudent = Student::factory()->create(['school_id' => null]);
    $softDeletedStudent->delete();

    runBackfillStudentsSchoolIdMigration();

    expect(Student::withTrashed()->find($softDeletedStudent->id)->school_id)->toBe($activeSchool->id);
});

test('backfill leaves rows untouched when there is not exactly one active school', function () {
    School::factory()->count(2)->create(['is_active' => true]);
    $studentWithoutSchool = Student::factory()->create(['school_id' => null]);

    runBackfillStudentsSchoolIdMigration();

    expect($studentWithoutSchool->fresh()->school_id)->toBeNull();
});
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `./vendor/bin/pest tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php`
Expected: FAIL — `Failed opening required '.../2026_09_12_000000_backfill_students_school_id.php'` (file belum ada).

- [ ] **Step 3: Buat migration**

Create `database/migrations/2026_09_12_000000_backfill_students_school_id.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill students.school_id for rows created through the manual
     * "Tambah Santri" form before StudentService started assigning it.
     * Those rows were invisible to every tenancy-guarded endpoint — fee
     * assignments, bills and payments all returned 404 for them.
     *
     * Single-tenant today: assigns the one active school. When there isn't
     * exactly one active school we skip instead of guessing.
     *
     * Data only — no schema change. Includes soft-deleted rows so a later
     * restore works. down() is intentionally a no-op: backfilled rows can't be
     * told apart afterwards, and re-orphaning them would re-break fees.
     */
    public function up(): void
    {
        $activeSchoolIds = DB::table('schools')->where('is_active', true)->pluck('id');

        if ($activeSchoolIds->count() !== 1) {
            return;
        }

        DB::table('students')
            ->whereNull('school_id')
            ->update(['school_id' => $activeSchoolIds->first()]);
    }

    public function down(): void
    {
        // Intentionally empty — see up().
    }
};
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `./vendor/bin/pest tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php`
Expected: 3 PASS.

- [ ] **Step 5: Full suite + commit**

```bash
./vendor/bin/pest
./vendor/bin/pint database/migrations/2026_09_12_000000_backfill_students_school_id.php tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php
git add database/migrations/2026_09_12_000000_backfill_students_school_id.php tests/Feature/Migrations/BackfillStudentsSchoolIdTest.php
git commit -m "fix(students): backfill school_id for students created manually

14 production students created via the Tambah Santri form have
school_id NULL and cannot get a fee scheme. Assign them the single
active school; skip when that is ambiguous.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Verifikasi di lokal dengan data salinan production

Tidak ada kode baru — ini gerbang verifikasi sebelum PR. DB lokal `ribath_app_local` adalah salinan prod (14 santri `school_id` NULL).

- [ ] **Step 1: Cek kondisi sebelum**

```bash
psql -h 127.0.0.1 -U postgres -d ribath_app_local -Atc "select count(*) from students where school_id is null"
```
Expected: `14`

- [ ] **Step 2: Jalankan migration di lokal**

```bash
php artisan migrate --no-interaction
psql -h 127.0.0.1 -U postgres -d ribath_app_local -Atc "select count(*) from students where school_id is null"
```
Expected: migration `2026_09_12_000000_backfill_students_school_id` DONE, lalu `0`.

- [ ] **Step 3: Uji flow di UI (`http://localhost:8080`, jalankan `npm run dev` di ribath-masjid-hub + DBngin Postgres)**

1. Buka Data Santri → banner kini menghitung **20** santri belum punya skema biaya (sebelumnya 6).
2. Buka santri lama, mis. "ach.aly at-thoifuriy" → tab **Biaya** → **Set Skema Biaya** → pilih `1448/1449 (aktif)` → **Simpan**.
   Expected: toast "Skema biaya berhasil di-set — 1 jenis biaya ditambahkan.", kartu "SPP Bulanan Rp1.000.000" muncul, console tanpa 404.
3. Tambah santri baru lewat form Tambah Santri → buka tab Biaya → Set Skema Biaya berhasil (membuktikan Task 1, bukan hanya backfill).

- [ ] **Step 4: Push & PR (setelah user setuju)**

```bash
git push -u origin fix/manual-student-school-id
gh pr create --title "fix(students): manual students get school_id so fee scheme can be set" \
  --body "## Problem
Admin tidak bisa Set Skema Biaya untuk santri yang ditambahkan manual (bukan PSB) — toast 'Gagal menyimpan — Not Found'.

## Root cause
StudentService::createStudent() tidak mengisi students.school_id, sehingga santri manual tersimpan dengan school_id NULL dan ditolak 404 oleh EnsuresActiveSchoolTenancy di semua endpoint keuangan (fee assignments, exceptions, bills, payments). Santri PSB aman karena PsbService mengisi school_id dari registrasi. Di production ada 14 santri terdampak.

## Fix
- StudentService mengisi school_id sekolah aktif (server-side) saat create.
- Migration 2026_09_12_000000_backfill_students_school_id mengisi baris NULL dengan satu-satunya sekolah aktif (skip bila ambigu).

## Tests
- create student manually assigns the active school
- student created via manual Tambah Santri form can get a fee snapshot (regresi end-to-end)
- 3 test untuk migration backfill
- Diverifikasi di lokal dengan salinan DB production: 14 → 0 santri tanpa school_id, Set Skema Biaya berhasil di UI.

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

- [ ] **Step 5: Deploy ke VPS — HANYA atas instruksi eksplisit user**

`deploy.sh` di VPS hanya menjalankan migrasi bila diberi flag `--migrate` (flag ini selalu membuat backup `pg_dump` pre-migrate lebih dulu). Tanpa `--migrate`, kode ter-deploy tapi backfill TIDAK jalan. (`scripts/deploy.sh` di repo sudah disamakan dengan versi VPS pada 2026-09-12.)
```bash
git push origin main   # deploy.sh meng-clone dari GitHub
ssh hyperscore-vps "bash /srv/www/ribath-backend/scripts/deploy.sh --migrate"
ssh hyperscore-vps 'cd /srv/www/ribath-backend/current && php artisan tinker --execute "echo App\Models\Student::whereNull(\"school_id\")->count();"'
```
Expected: `0`.

---

## Di luar plan ini (follow-up, perlu keputusan terpisah)

1. **Frontend menelan error 404 di tab Biaya.** `StudentFeesTab` tidak memeriksa `isError`, jadi GET yang gagal tampil sebagai "Belum ada skema biaya", dan toast hanya "Not Found". Setelah Task 1–2 penyebabnya hilang, tapi layar ini akan tetap menyesatkan untuk error lain (mis. 500/403). Usulan: tampilkan error state bila `useStudentFeeAssignments` error.
2. **Cegah terulang di level skema:** jadikan `students.school_id` NOT NULL setelah backfill. Butuh mengubah FK `students_school_id_foreign` dari `ON DELETE SET NULL` ke `RESTRICT` — perubahan skema yang lebih besar, sebaiknya dibahas dulu.
3. **`StudentFactory` tidak mengisi `school_id`** — membuat test mudah melewatkan bug tenancy. Bisa di-default ke `School::factory()`, tapi berdampak ke banyak test yang ada.
