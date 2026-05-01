# School Profile Management (Settings → Profil)

> **For Claude:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Wire the existing Settings → Profil tab to the real backend so admins can manage their school's identity (name, address, phone, email) and upload a custom logo. Currently the tab is a 481-line UI mockup with hardcoded `defaultValue` inputs and `alert()` save buttons. This plan replaces the Profil tab end-to-end and adds a `logo_path` column to the `schools` table; the per-school logo flows through to the PDF export header automatically.

**Architecture:** REST endpoints under `/api/v1/schools/{school}` (extending the existing `SchoolController`) backed by a thin `SchoolService` that handles updates, image processing (resize to 1024×1024 via Spatie Image, replace-on-upload, cleanup-on-delete), and storage on Laravel's `public` disk. Frontend uses TanStack Query + react-hook-form + Zod, replacing the mockup in `src/pages/settings/index.tsx`. Logo uploads commit immediately on file-pick (separate from text-field save) — established UX pattern from GitHub/Slack/etc.

**Tech Stack:** Laravel 12 + Spatie Image (image processing), Spatie Permission (new `manage-school-profile`), Sanctum, Pest tests, public disk storage. Frontend: React 18 + TanStack Query + react-hook-form + Zod + shadcn `Form`, `Input`, `Textarea`, `Button`, `Dialog` for the larger logo preview.

**Decisions captured:**

- **D1** Tab label: keep **"Profil"** (more general — same tab handles user profile fields too in future scope; for this PR we focus on school identity sub-section, but the tab name doesn't change).
- **D2** Logo placement: **inline in the same tab** as name/address. Card-based upload zone with drag-drop + click. Includes a "Lihat ukuran besar" action that opens a `Dialog` showing the logo at natural size plus a thumbnail of how it appears in the PDF header (mirrors the PDF preview modal pattern from the previous feature).
- **D3** Logo formats: **PNG, JPEG, WebP only** for v1. Defer SVG until proper sanitisation is in place.
- **D4** Server-side resize: **yes**, max 1024×1024 via Spatie Image. Keeps storage and PDF render sizes predictable.
- **D5** Permission: new **`manage-school-profile`** Spatie permission. Granular intent, easy to assign separately later.
- **D6** Indonesian throughout the UI.
- **Q1** Storage disk: **`public`** disk (served at `/storage/school-logos/...`). Symlink already exists from the prior PDF deploy.
- **Q2** "Larger preview view": **Dialog modal** with natural-size logo + PDF-header thumbnail preview.
- **Storage filename strategy:** `school-logos/{school-id}-{uuid}.{ext}`. Old logo file is deleted when a new one is uploaded or when the user clicks "Hapus logo".

**Stale fields to drop from the Profil tab (with rationale):**

- **`Tahun Ajaran`** — duplicates `/akademik/tahun-ajaran` (active academic year management).
- **`Biaya SPP`** — fundamentally wrong as a single global value. Per the team meeting note (`memory/project_fee_feature_design_note.md`), fees are per-academic-year × per-fee-type × per-school with rate-snapshot semantics at enrollment. That model can't fit on a Settings tab; will live in `/keuangan/tarif-tahunan` as a separate feature.

**Out of scope (explicit follow-ups):**

1. The other Settings sub-sections — Profil Pengguna (user profile), Notifikasi, Keamanan (password change wiring), Tampilan, Database — each has its own backend integration and deserves its own PR.
2. Per-school logo override of the SVG default in *non-PDF* contexts (sidebar, hero, etc.). Out of scope for this PR; future feature.
3. SVG logo support with proper sanitisation.
4. Multi-school admin UX (a single user managing multiple schools).
5. The fee management feature itself.
6. Image cropping UI (we just resize-on-upload; if cropping becomes valuable, add a follow-up with a crop component).

**Branches:**

- Backend: commit directly on `main` (existing convention; deploys from main).
- Frontend: commit directly on `feature/laravel-backend-migration` (existing convention).
- No new feature branches — keeps the deploy flow consistent with the previous feature.

---

## Task 1: Migration — add `logo_path` to schools

**Files:**
- Create: `database/migrations/2026_05_01_100000_add_logo_path_to_schools_table.php`

**Step 1: Generate**

Run:
```bash
php artisan make:migration add_logo_path_to_schools_table --table=schools
```

**Step 2: Body**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
```

**Step 3: Run + verify**

```bash
php artisan migrate
php artisan db:show schools | grep logo_path
```
Expected: `logo_path` listed as nullable VARCHAR.

**Step 4: Commit**

```bash
git add database/migrations/2026_05_01_100000_add_logo_path_to_schools_table.php \
  && git commit -m "Add logo_path column to schools for per-school PDF branding"
```

---

## Task 2: School model — fillable + logo_url accessor

**Files:**
- Modify: `app/Models/School.php`

**Step 1: Add `logo_path` to `$fillable`** (or whatever the existing pattern is — match it).

**Step 2: Add `logo_url` accessor**

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

protected function logoUrl(): Attribute
{
    return Attribute::make(
        get: fn () => $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null,
    );
}
```

Add `logo_url` to `$appends` so it's included in JSON responses.

**Step 3: Run existing model tests**

```bash
php artisan test --filter=School
```
Expected: green.

**Step 4: Commit**

```bash
git add app/Models/School.php \
  && git commit -m "Add logo_url accessor to School model"
```

---

## Task 3: Permission seeder — `manage-school-profile`

**Files:**
- Modify: `database/seeders/RolePermissionSeeder.php`

**Step 1: Add the permission**

Find the permissions array (the one with `view-schedules`, `manage-schedules`, etc. — match the existing pattern). Add `manage-school-profile`.

**Step 2: Assign to roles**

In the role-permission mapping (super_admin and pengurus_pesantren get this permission), add `manage-school-profile`.

**Step 3: Test the seeder**

```bash
php artisan test --filter=RolePermission
```
Expected: green. Update test expectations if the seeder is asserted against a fixed permission count.

**Step 4: Commit**

```bash
git add database/seeders/RolePermissionSeeder.php tests/Feature/Admin/RolePermissionSeederTest.php \
  && git commit -m "Add manage-school-profile permission to RolePermissionSeeder"
```

**Step 5 (deploy time only): run with `--seed`**

The deploy that ships this feature must include the `--seed` flag so the new permission lands in production. Captured in Task 16.

---

## Task 4: Form Request — UpdateSchoolRequest

**Files:**
- Create: `app/Http/Requests/Admin/UpdateSchoolRequest.php`

**Step 1: Body**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces permission:manage-school-profile
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'email'   => ['nullable', 'email', 'max:255'],
        ];
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Requests/Admin/UpdateSchoolRequest.php \
  && git commit -m "Add UpdateSchoolRequest validation"
```

---

## Task 5: Form Request — UploadSchoolLogoRequest

**Files:**
- Create: `app/Http/Requests/Admin/UploadSchoolLogoRequest.php`

**Step 1: Body**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadSchoolLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                File::image()
                    ->types(['png', 'jpeg', 'jpg', 'webp'])
                    ->max(2 * 1024) // 2 MB
                    ->dimensions(
                        \Illuminate\Validation\Rule::dimensions()
                            ->minWidth(256)
                            ->minHeight(256),
                    ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.image'      => 'File harus berupa gambar.',
            'logo.mimetypes'  => 'Format yang didukung: PNG, JPEG, WebP.',
            'logo.max'        => 'Ukuran file maksimal 2 MB.',
            'logo.dimensions' => 'Resolusi minimal 256×256 piksel.',
        ];
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Requests/Admin/UploadSchoolLogoRequest.php \
  && git commit -m "Add UploadSchoolLogoRequest with image MIME, size, and dimension validation"
```

---

## Task 6: SchoolService — update + logo upload + logo delete

**Files:**
- Create: `app/Services/SchoolService.php`

**Step 1: Install Spatie Image** (if not already present):

```bash
composer require spatie/image
```

**Step 2: Service body**

```php
<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Image;

class SchoolService
{
    private const LOGO_DISK = 'public';
    private const LOGO_DIRECTORY = 'school-logos';
    private const LOGO_MAX_DIMENSION = 1024;

    public function update(School $school, array $data): School
    {
        $school->update($data);
        return $school->fresh();
    }

    public function uploadLogo(School $school, UploadedFile $file): School
    {
        // Resize first to a temp path so we never store the raw upload
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $extension = $extension === 'jpg' ? 'jpeg' : $extension;
        $filename = self::LOGO_DIRECTORY . '/' . $school->id . '-' . Str::uuid() . '.' . $extension;

        $tempPath = $file->getRealPath();
        Image::load($tempPath)
            ->fit(\Spatie\Image\Enums\Fit::Max, self::LOGO_MAX_DIMENSION, self::LOGO_MAX_DIMENSION)
            ->save($tempPath);

        Storage::disk(self::LOGO_DISK)->putFileAs(
            self::LOGO_DIRECTORY,
            $tempPath,
            basename($filename),
        );

        // Delete the previous logo (if any) so we don't accumulate orphans
        $previousPath = $school->logo_path;
        $school->update(['logo_path' => $filename]);
        if ($previousPath && $previousPath !== $filename) {
            Storage::disk(self::LOGO_DISK)->delete($previousPath);
        }

        return $school->fresh();
    }

    public function deleteLogo(School $school): School
    {
        if ($school->logo_path) {
            Storage::disk(self::LOGO_DISK)->delete($school->logo_path);
            $school->update(['logo_path' => null]);
        }
        return $school->fresh();
    }
}
```

**Step 3: Commit**

```bash
composer.lock composer.json app/Services/SchoolService.php
git add . && git commit -m "Add SchoolService for update + logo upload/delete with image resize"
```

---

## Task 7: Extend SchoolController

**Files:**
- Modify: `app/Http/Controllers/Api/Admin/SchoolController.php`

**Step 1: Refactor to thin controller**

Existing `index()` stays. Add:

```php
public function show(School $school): JsonResponse
{
    return $this->successResponse(
        $school->only(['id', 'name', 'address', 'phone', 'email', 'logo_url']),
        'School retrieved',
    );
}

public function update(UpdateSchoolRequest $request, School $school): JsonResponse
{
    $updated = $this->schoolService->update($school, $request->validated());
    return $this->successResponse($updated, 'School updated');
}

public function uploadLogo(UploadSchoolLogoRequest $request, School $school): JsonResponse
{
    $updated = $this->schoolService->uploadLogo($school, $request->file('logo'));
    return $this->successResponse($updated, 'Logo uploaded');
}

public function deleteLogo(School $school): JsonResponse
{
    $updated = $this->schoolService->deleteLogo($school);
    return $this->successResponse($updated, 'Logo removed');
}
```

Inject `SchoolService` via constructor (match existing controller patterns).

Update `index()` to also include `logo_url`:

```php
$activeSchools = School::where('is_active', true)
    ->select('id', 'name', 'logo_path')
    ->get()
    ->map(fn ($s) => $s->only(['id', 'name', 'logo_url']));
```

**Step 2: Commit**

```bash
git add app/Http/Controllers/Api/Admin/SchoolController.php \
  && git commit -m "Extend SchoolController with show, update, uploadLogo, deleteLogo"
```

---

## Task 8: Routes

**Files:**
- Modify: `routes/api.php`

**Step 1: Add routes inside the existing `Route::prefix('v1')->group`**

Replace the existing single `/schools` route with a small group:

```php
Route::prefix('schools')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [SchoolController::class, 'index']);
    Route::get('/{school}', [SchoolController::class, 'show'])
        ->middleware('permission:manage-school-profile');
    Route::put('/{school}', [SchoolController::class, 'update'])
        ->middleware('permission:manage-school-profile');
    Route::post('/{school}/logo', [SchoolController::class, 'uploadLogo'])
        ->middleware('permission:manage-school-profile');
    Route::delete('/{school}/logo', [SchoolController::class, 'deleteLogo'])
        ->middleware('permission:manage-school-profile');
});
```

Note: `index` stays open to anyone authenticated (it's used by the school selector in various places). Only the per-school read/write paths require the new permission.

**Step 2: Verify**

```bash
php artisan route:list --path=schools
```

Expected: 5 routes registered.

**Step 3: Commit**

```bash
git add routes/api.php \
  && git commit -m "Add school profile read/write/upload/delete routes"
```

---

## Task 9: PDF Blade integration — prefer school logo over default

**Files:**
- Modify: `app/Services/TeachingScheduleService.php` (`buildTeacherExportViewModel` + helper)

**Step 1: Resolve the active school's logo data URI**

Add a helper alongside `resolveDefaultLogoDataUri()`:

```php
private function resolveSchoolLogoDataUri(?School $school): ?string
{
    if (! $school?->logo_path) {
        return $this->resolveDefaultLogoDataUri();
    }

    $absolutePath = Storage::disk('public')->path($school->logo_path);
    if (! file_exists($absolutePath)) {
        return $this->resolveDefaultLogoDataUri();
    }

    $mime = mime_content_type($absolutePath) ?: 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absolutePath));
}
```

**Step 2: Wire into the view model**

Replace the existing `'logo_data_uri' => $this->resolveDefaultLogoDataUri()` line with:

```php
'logo_data_uri' => $this->resolveSchoolLogoDataUri($teacher->school),
```

**Step 3: Add a unit test for fallback behaviour**

Add to `tests/Feature/Services/TeachingScheduleService/BuildTeacherExportViewModelTest.php`:

- school with no logo → falls back to default logo data URI
- school with logo file present → uses school logo
- school with `logo_path` set but file missing → falls back to default

**Step 4: Run tests**

```bash
php artisan test --filter=BuildTeacherExportViewModel
```

**Step 5: Commit**

```bash
git add app/Services/TeachingScheduleService.php tests/Feature/Services/TeachingScheduleService/ \
  && git commit -m "PDF export prefers school.logo_path over bundled default"
```

---

## Task 10: Backend feature tests

**Files:**
- Create: `tests/Feature/Admin/SchoolProfileTest.php`

**Step 1: Test cases (Pest)**

Cover:

1. `unauthenticated cannot access show` → 401
2. `user without manage-school-profile cannot show` → 403
3. `authenticated user with permission can show their school` → 200 + payload shape
4. `update validates name as required` → 422
5. `update accepts name + nullable optional fields` → 200 + DB row updated
6. `upload validates image MIME — text/plain rejected` → 422
7. `upload validates max size — file too big rejected` → 422
8. `upload validates min dimensions — 100×100 rejected` → 422
9. `upload happy path — PNG saved to public disk + logo_path persisted + logo_url returned` → 200 + file exists
10. `uploading a second logo deletes the previous file` → previous file gone
11. `delete removes file and clears logo_path` → 200 + DB null + file gone
12. `multi-tenant: user cannot update a different school's profile` → 403 / 404 (depending on policy approach)

Use `Storage::fake('public')` to keep tests hermetic.

**Step 2: Run**

```bash
php artisan test --filter=SchoolProfileTest
```

**Step 3: Commit**

```bash
git add tests/Feature/Admin/SchoolProfileTest.php \
  && git commit -m "Add feature tests for school profile management endpoints"
```

---

## Task 11: Frontend — schoolService

**Files (frontend repo):**
- Create: `src/services/api/schoolService.ts`

**Step 1: Body**

```ts
import { apiClient } from './apiClient';

export interface School {
  id: string;
  name: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  logo_url: string | null;
}

export interface UpdateSchoolPayload {
  name: string;
  address?: string | null;
  phone?: string | null;
  email?: string | null;
}

export async function getSchool(schoolId: string): Promise<School> {
  const response = await apiClient.get<School>(`/schools/${schoolId}`);
  return response.data;
}

export async function updateSchool(schoolId: string, payload: UpdateSchoolPayload): Promise<School> {
  const response = await apiClient.put<School>(`/schools/${schoolId}`, payload);
  return response.data;
}

export async function uploadSchoolLogo(schoolId: string, file: File): Promise<School> {
  const formData = new FormData();
  formData.append('logo', file);
  const response = await apiClient.upload<School>(`/schools/${schoolId}/logo`, formData);
  return response.data;
}

export async function deleteSchoolLogo(schoolId: string): Promise<School> {
  const response = await apiClient.delete<School>(`/schools/${schoolId}/logo`);
  return response.data;
}
```

**Step 2: Commit**

```bash
git add src/services/api/schoolService.ts \
  && git commit -m "Add schoolService — show, update, uploadLogo, deleteLogo"
```

---

## Task 12: Frontend — useSchool hook

**Files (frontend repo):**
- Create: `src/hooks/useSchool.ts`

**Step 1: Hook**

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  getSchool, updateSchool, uploadSchoolLogo, deleteSchoolLogo,
  type School, type UpdateSchoolPayload,
} from '@/services/api/schoolService';

const schoolKey = (id: string) => ['school', id] as const;

export function useSchool(schoolId: string) {
  return useQuery({
    queryKey: schoolKey(schoolId),
    queryFn: () => getSchool(schoolId),
    enabled: Boolean(schoolId),
  });
}

export function useUpdateSchool(schoolId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: UpdateSchoolPayload) => updateSchool(schoolId, payload),
    onSuccess: (data) => qc.setQueryData(schoolKey(schoolId), data),
  });
}

export function useUploadSchoolLogo(schoolId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadSchoolLogo(schoolId, file),
    onSuccess: (data) => qc.setQueryData(schoolKey(schoolId), data),
  });
}

export function useDeleteSchoolLogo(schoolId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => deleteSchoolLogo(schoolId),
    onSuccess: (data) => qc.setQueryData(schoolKey(schoolId), data),
  });
}
```

**Step 2: Commit**

```bash
git add src/hooks/useSchool.ts \
  && git commit -m "Add useSchool TanStack Query hooks"
```

---

## Task 13: Frontend — SchoolLogoUploadCard component

**Files (frontend repo):**
- Create: `src/pages/settings/components/SchoolLogoUploadCard.tsx`
- Create: `src/pages/settings/components/SchoolLogoPreviewModal.tsx`

**Step 1: SchoolLogoUploadCard requirements**

Visual:
- Square card, ~192×192 default size, rounded, light dashed border when empty
- Empty state: muted upload icon + "Klik atau seret untuk unggah logo" + format/size hint
- Filled state: logo image fills the card with `object-fit: contain` on a subtle checkered/neutral background
- Hover (filled): semi-transparent overlay with "Ubah" + "Lihat" + "Hapus" actions

Behaviour:
- Click anywhere on the card → file picker
- Drag-drop file onto the card → same as picking
- Client-side validation (MIME + size) → show inline error toast, no API call
- On valid file → call `useUploadSchoolLogo` mutation immediately (no separate save button); show inline spinner + disable interactions during upload
- On success → toast `"Logo berhasil diperbarui"`
- On error → toast destructive variant with API error message
- "Lihat" button → opens `SchoolLogoPreviewModal` (Task below)
- "Hapus" button → confirmation dialog (`AlertDialog`) → `useDeleteSchoolLogo` mutation
- Disabled (read-only mode) when user lacks `manage-school-profile` — pure preview

Props:
```ts
interface SchoolLogoUploadCardProps {
  schoolId: string;
  logoUrl: string | null;
  schoolName: string;
  canEdit: boolean;
}
```

**Step 2: SchoolLogoPreviewModal requirements**

shadcn `Dialog` containing:
- Title: "Pratinjau Logo"
- Body, two columns on desktop / stacked on mobile:
  - Left: logo at natural max size on a neutral background (chessboard-ish to show transparency)
  - Right: a small mock "PDF Header" — a wireframe of the export header with logo + dummy school name to show how it looks in the exported PDF
- Footer: Close button

Props:
```ts
interface SchoolLogoPreviewModalProps {
  open: boolean;
  onClose: () => void;
  logoUrl: string;
  schoolName: string;
}
```

**Step 3: Commit**

```bash
git add src/pages/settings/components/ \
  && git commit -m "Add SchoolLogoUploadCard + SchoolLogoPreviewModal components"
```

---

## Task 14: Frontend — rewrite Settings → Profil tab

**Files (frontend repo):**
- Modify: `src/pages/settings/index.tsx`

**Step 1: Strategy**

Replace the entire `{activeTab === 'general'}` block (the current "Pengaturan Umum" rendering) with a new component that:
- Drops the four hardcoded fields (Nama Pondok placeholder, Alamat placeholder, Tahun Ajaran, Biaya SPP)
- Adds a new section: **"Profil Pesantren"** at the top of the Profil tab? Or stays under "Umum"? Per D1 we keep the tab name "Profil"; the section we render here is school identity.

For minimal disruption: keep the tab structure but rewrite ONLY the `general` (Umum) tab to be the school profile. Leave other tabs (`profile`, `notifications`, `security`, `appearance`, `database`) untouched — they're follow-ups.

**Step 2: Extract new component**

`src/pages/settings/components/SchoolProfileSection.tsx`:

- Resolves the active school via the existing app context (the user's session is tied to a school; check `AuthContext` for the current school id — if not present yet, plumb it). For a single-school deployment today, can read from `useSchools` (the active list).
- Uses react-hook-form + Zod schema:
  ```ts
  const schoolProfileSchema = z.object({
    name: z.string().min(1, 'Nama wajib diisi').max(150),
    address: z.string().max(500).nullable().optional(),
    phone: z.string().max(20).nullable().optional(),
    email: z.string().email('Email tidak valid').max(255).nullable().or(z.literal('')),
  });
  ```
- shadcn `Form`, `FormField`, `FormControl`, `Input`, `Textarea`, `Button`
- Renders the `SchoolLogoUploadCard` at the top of the section (left side or full-width, mobile-responsive)
- Save button is `disabled` when `!form.formState.isDirty` or while mutating
- On success → toast + `form.reset(updatedSchool)` to clear dirty state
- On 422 → map field errors back into the form via `form.setError`
- Permission gate: if user doesn't have `manage-school-profile`, render the form in **disabled** mode (read-only inputs + hidden save button) but still show data

**Step 3: Wire into the page**

In `src/pages/settings/index.tsx`, replace the contents of the `general` tab branch with:

```tsx
{activeTab === 'general' && <SchoolProfileSection />}
```

**Step 4: Commit**

```bash
git add src/pages/settings/ \
  && git commit -m "Rewrite Settings Umum tab as wired SchoolProfileSection

Drops the hardcoded Tahun Ajaran + Biaya SPP placeholder fields (they
duplicate /akademik/tahun-ajaran and don't fit the per-AY rate-snapshot
model the fee feature needs — see project_fee_feature_design_note).

Adds shadcn-based form (react-hook-form + Zod), live save with toast
feedback, dirty-state save button, permission-gated read-only mode, and
an inline SchoolLogoUploadCard with larger preview + drag-drop + replace."
```

---

## Task 15: Bump KEEP_RELEASES from 5 to 10 in deploy.sh

**Files:**
- Modify: `scripts/deploy.sh`

**Step 1: Change**

Locate `KEEP_RELEASES=5` and change to `KEEP_RELEASES=10`.

**Step 2: Commit**

```bash
git add scripts/deploy.sh \
  && git commit -m "Bump KEEP_RELEASES to 15 to preserve more rollback points"
```

---

## Task 16: VPS deploy — backend (with --seed)

**Step 1: Push**

```bash
git push origin main
```

**Step 2: Sync deploy.sh on the server (one-time mirroring after KEEP_RELEASES change)**

```bash
pscp scripts/deploy.sh ak_rocks@103.157.97.233:/srv/www/ribath-backend/scripts/deploy.sh
```

(Or use the standard sync approach already established.)

**Step 3: DB backup BEFORE deploy** (because Task 1 migrates the schema):

```bash
ssh ak_rocks@103.157.97.233 \
  "PGPASSWORD='...' pg_dump -h 127.0.0.1 -U ak_rocks -d ribath_app_prod \
    --format=plain --no-owner --no-acl \
    -f /srv/www/ribath-backend/backups/ribath_app_prod_pre_school_profile_$(date +%Y%m%d_%H%M%S).sql"
```

**Step 4: Deploy with --seed** (so the new permission lands):

```bash
ssh ak_rocks@103.157.97.233 "bash /srv/www/ribath-backend/scripts/deploy.sh --seed"
```

**Step 5: Smoke test**

- `curl -sI -H "Authorization: Bearer $TOKEN" https://apiribath.hyperscore.cloud/api/v1/schools/$SCHOOL_ID` → 200
- Check the `manage-school-profile` permission exists in the DB:
  ```bash
  ssh ak_rocks@103.157.97.233 "echo SELECT name FROM permissions WHERE name = 'manage-school-profile' | sudo -u postgres psql ribath_app_prod"
  ```

**Step 6: Verify storage:link is intact** (it should be, but the deploy `storage:link --force` step will recreate it if needed):

```bash
ssh ak_rocks@103.157.97.233 "ls -la /srv/www/ribath-backend/current/public/storage"
```

---

## Task 17: VPS deploy — frontend

```bash
ssh ak_rocks@103.157.97.233 "bash /srv/www/ribath-masjid-hub/scripts/deploy.sh feature/laravel-backend-migration"
```

---

## Task 18: Manual QA on production

Run on `https://ribath.hyperscore.cloud/settings`:

- [ ] Navigate to Settings → Umum tab
- [ ] Existing school name displays correctly (was hardcoded before, now from API)
- [ ] Edit name → Save → succeeds, toast shows, dirty state clears, refresh confirms persistence
- [ ] Email validation: enter invalid email → inline error blocks save
- [ ] Logo card shows current/default logo
- [ ] Click card → file picker; choose a valid PNG ≥256×256 ≤2MB → uploads, card updates, toast shows
- [ ] Click "Lihat ukuran besar" → modal opens with natural-size logo + PDF header preview thumbnail
- [ ] Drag-drop a JPEG → uploads
- [ ] Drag-drop a 5MB image → client-side error, no API call
- [ ] Drag-drop a .txt file → client-side error
- [ ] Click "Hapus" → confirmation dialog → confirm → logo removed, default logo returns
- [ ] Open `/jadwal` → "Per Ustadz" → Export PDF for any teacher → **PDF header now shows the school's uploaded logo** (or the default if removed)
- [ ] Log in as a user without `manage-school-profile` permission → form fields are disabled, no Save button, no upload card actions
- [ ] DevTools → Network → Offline → page still loads (stale cache), interactions disabled with appropriate feedback

---

## Acceptance criteria

1. Backend tests green — `php artisan test --filter="SchoolProfile|BuildTeacherExportViewModel"` returns all green; full suite unaffected.
2. Frontend lints + typechecks clean — `npm run lint` and `npx tsc --noEmit -p tsconfig.app.json` exit 0 (modulo pre-existing project-wide errors).
3. Settings → Umum tab fully wired to backend — no remaining `defaultValue=` placeholders, no `alert()` calls in the Profil section.
4. Logo upload, replace, and delete all work end-to-end on production.
5. PDF export header uses the per-school logo when set; falls back to default when null or file missing.
6. New permission `manage-school-profile` enforced on all four per-school endpoints; users without it are read-only.
7. Stale fields (Tahun Ajaran, Biaya SPP) are gone from the UI.
8. Old logo files are cleaned up on replace and on delete (no orphan files in `storage/app/public/school-logos/`).

---

## Open follow-ups (not in this plan)

1. **Other Settings tabs** — Profil (user), Notifikasi, Keamanan, Tampilan, Database — each is its own feature.
2. **Per-school logo override of the sidebar / hero / favicon** — currently the React frontend hardcodes `logo-ribath-new.png`. A future feature could read the per-school logo from the API and render it everywhere.
3. **Image cropping UI** — for now we just resize-on-upload. If users want pixel-precise framing, add a follow-up with a crop component.
4. **SVG logo support** — defer until proper sanitisation.
5. **Multi-school admin UX** — when a user manages multiple schools, the Profil tab needs a school selector. Today it's implicit (the active school).
6. **The fee management feature** — see `memory/project_fee_feature_design_note.md`.
