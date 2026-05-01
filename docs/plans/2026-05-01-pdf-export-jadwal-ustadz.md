# PDF Export — Jadwal Mengajar Ustadz

> **For Claude:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Allow authenticated admins/pengurus to export and preview a teacher's weekly teaching schedule as a PDF document, with a user-selectable orientation (portrait for phone view, landscape for print). The PDF foundation built here is reusable for future reports (rapor santri, rekap absensi, kwitansi keuangan, etc).

**Architecture:** Server-side PDF rendering with `spatie/laravel-pdf` (Browsershot + headless Chromium). New REST endpoint under the existing `teaching-schedules` route prefix returns a PDF binary inline; the React frontend renders it in an iframe inside a preview modal. The endpoint reads canonical schedule data straight from the Laravel database — no payload hand-off from the client.

**Tech Stack:** Laravel 12 + spatie/laravel-pdf (Browsershot), Sanctum auth, Spatie Permission (`view-schedules` reused — no new permission), Pest tests, React + TanStack Query (frontend), Tailwind via CDN inside Blade for PDF styling.

**Decisions captured (from brainstorm 2026-05-01):**

- **D1** Auth: Sanctum + existing `permission:view-schedules`. No new permission.
- **D2** Orientation: user-toggle in preview modal. Two distinct layouts:
  - **Landscape** = weekly grid (7 columns × time slots), best for printing.
  - **Portrait** = grouped-by-day list (matches on-screen Ringkasan), best for phone reading.
- **D3** Header data: `schools` table (`name`, `address`, `phone`, `email`). No `logo` column today — placeholder asset at `public/images/default-school-logo.png`. Logo column deferred until needed.
- **D4** Footer: `Diekspor pada {Hari}, {dd MMMM yyyy} pukul {HH:mm} WIB`. No signature lines yet.
- **D5** Filename: code-based — `Jadwal-{teacher.code}-Sem{n}-{academic_year.name}.pdf` (slashes in academic year name replaced with `-`).
- **D6** Language: Indonesian throughout.
- **D7** Offline behavior (frontend): button disabled with tooltip `"Export PDF memerlukan koneksi"` when `!navigator.onLine`.

**Out of scope (future plans):**

- Bulk export (all teachers in one PDF).
- Class-level / student-facing schedule PDFs (different controller, same blade pattern reusable).
- School logo upload + storage (separate feature).
- E-signature lines.
- Caching rendered PDFs.

---

## Task 1: Install spatie/laravel-pdf

**Files:**
- Modify: `composer.json`, `composer.lock`

**Step 1: Install package**

Run:
```bash
composer require spatie/laravel-pdf
```

This pulls in `spatie/browsershot` and `spatie/image` as transitive deps.

**Step 2: Verify install**

Run:
```bash
php artisan about | grep -i pdf
```
Expected: `Spatie\LaravelPdf\PdfServiceProvider` listed.

**Step 3: Commit**

```bash
git add composer.json composer.lock && git commit -m "Install spatie/laravel-pdf for server-side PDF rendering"
```

---

## Task 2: Local Chromium / Node setup verification

**Goal:** Browsershot needs Node + Chromium reachable from PHP. Confirm before writing code so we don't debug a missing-binary error mid-implementation.

**Step 1: Confirm Node + npm available**

Run:
```bash
node --version && npm --version
```
Expected: both return versions (Node ≥ 18).

**Step 2: Install puppeteer (provides bundled Chromium)**

Run from project root:
```bash
npm install puppeteer
```

This installs Chromium under `node_modules/puppeteer/.local-chromium/`. Browsershot auto-discovers it.

**Step 3: Smoke test**

Create a tinker snippet — verify Browsershot can launch Chromium:
```bash
php artisan tinker
>>> Spatie\Browsershot\Browsershot::html('<h1>OK</h1>')->save(storage_path('test.pdf'));
>>> exit
```
Expected: `storage/test.pdf` created with the heading rendered. Delete the test file after.

**Step 4: Commit**

```bash
git add package.json package-lock.json && git commit -m "Add puppeteer for bundled Chromium (Browsershot dependency)"
```

---

## Task 3: Default school logo asset

**Files:**
- Create: `public/images/default-school-logo.png`

**Step 1: Place asset**

Use a 256×256 placeholder PNG (mosque/book icon) at the path above. Either reuse an existing asset from the React frontend's `public/icons/` or generate a minimal placeholder. The Blade template references this file via `public_path('images/default-school-logo.png')` and embeds it as a base64 data-URI for offline-safe rendering.

**Step 2: Commit**

```bash
git add public/images/default-school-logo.png && git commit -m "Add default school logo placeholder for PDF exports"
```

---

## Task 4: Form Request — export query validation

**Files:**
- Create: `app/Http/Requests/Admin/ExportTeacherSchedulePdfRequest.php`

**Step 1: Write request class**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportTeacherSchedulePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-schedules') ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'uuid', 'exists:academic_years,id'],
            'semester'         => ['required', 'integer', Rule::in([1, 2])],
            'orientation'      => ['nullable', Rule::in(['portrait', 'landscape'])],
        ];
    }

    public function orientation(): string
    {
        return $this->validated('orientation', 'landscape');
    }
}
```

**Step 2: Commit**

```bash
git add app/Http/Requests/Admin/ExportTeacherSchedulePdfRequest.php && git commit -m "Add ExportTeacherSchedulePdfRequest validation"
```

---

## Task 5: Service method — assemble export view-model

**Files:**
- Modify: `app/Services/TeachingScheduleService.php`

**Step 1: Add `buildTeacherExportViewModel` method**

Returns a structured array consumed by the Blade template. Signature:

```php
public function buildTeacherExportViewModel(
    Teacher $teacher,
    string $academicYearId,
    int $semester,
): array
```

The method must:

1. Eager-load schedules for the teacher filtered by `academic_year_id` + `semester` + `is_active = true`, including relations: `school`, `academicYear`, `timeSlot`, `classLevel`, `subjectBook.subjectCategory`.
2. Sort by canonical day order (`monday → sunday`) then `time_slot.sort_order`.
3. Group schedules by `day_of_week` for the portrait list layout.
4. Build distinct ordered time-slot list for the landscape grid layout.
5. Compute totals: `total_sesi`, `total_kitab` (distinct `subject_book_id`), `total_kelas` (distinct `class_level_id`).
6. Resolve school info from `$teacher->school` (eager-loaded).
7. Return an array shaped as:

```php
[
    'school' => ['name' => ..., 'address' => ..., 'phone' => ..., 'email' => ...],
    'teacher' => ['full_name' => ..., 'code' => ...],
    'academic_year' => ['name' => ...],
    'semester' => 1|2,
    'schedules_sorted' => [...],   // flat, day+slot ordered
    'schedules_by_day' => [        // [{ day, label, items:[...] }, ...]
        ['day' => 'monday', 'label' => 'Senin', 'items' => [...]],
        ...
    ],
    'time_slots' => [...],          // ordered { id, label, sort_order }
    'totals' => ['sesi' => 12, 'kitab' => 4, 'kelas' => 2],
    'generated_at' => Carbon::now('Asia/Jakarta'),
]
```

**Day label map** (constant in the service):
```php
private const DAY_LABELS = [
    'monday' => 'Senin', 'tuesday' => 'Selasa', 'wednesday' => 'Rabu',
    'thursday' => 'Kamis', 'friday' => 'Jumat', 'saturday' => 'Sabtu',
    'sunday' => 'Ahad',
];
```

**Step 2: Add Pest unit test**

`tests/Unit/Services/TeachingScheduleService/BuildTeacherExportViewModelTest.php` — covers ordering, grouping, totals, day-label translation, missing relations safety.

Run:
```bash
php artisan test --filter=BuildTeacherExportViewModel
```

**Step 3: Commit**

```bash
git add app/Services/TeachingScheduleService.php tests/Unit/Services/TeachingScheduleService/ \
  && git commit -m "Add export view-model builder to TeachingScheduleService"
```

---

## Task 6: Blade template

**Files:**
- Create: `resources/views/pdf/teaching-schedule-teacher.blade.php`
- Create: `resources/views/pdf/_partials/header.blade.php`
- Create: `resources/views/pdf/_partials/footer.blade.php`

**Step 1: Layout strategy**

Single Blade file branches on `$orientation`. Tailwind via CDN at the top (`<script src="https://cdn.tailwindcss.com"></script>`). Inline `<style>` for `@page { size: A4 {{ orientation }}; margin: 12mm }` and any print-specific tweaks.

**Step 2: Sections**

**Header (both orientations):**
- Default logo (left, 48×48px, embedded as base64 from `public/images/default-school-logo.png`)
- School name (large), address (small muted), phone + email (small muted) — right-aligned next to logo
- Horizontal rule
- "Jadwal Mengajar" title + ustadz full name + ustadz code + "Semester {n} • TA {academic_year.name}"

**Body (landscape):**
- 7-column grid table (`Senin … Ahad`) with time-slot rows, identical structure to the on-screen calendar.
- Cells: subject_book.title (bold) + class_level.label below, blue tint background.
- Empty cells: `–`.

**Body (portrait):**
- For each day in `schedules_by_day`: day label header (uppercase, teal), then a list — `time_slot.label` left, `subject_book.title — class_level.label` right.
- Mirrors the on-screen Ringkasan visually so it's instantly recognizable.

**Totals card (both):**
- Three-stat row: `Total Sesi`, `Kitab`, `Kelas`.

**Footer (both):**
- "Diekspor pada {Hari, dd MMMM yyyy pukul HH:mm} WIB" — formatted via `Carbon::now('Asia/Jakarta')->locale('id')->isoFormat('dddd, D MMMM Y [pukul] HH.mm')`.
- "Halaman {x} dari {y}" using Browsershot's footer template option.

**Step 3: Render preview during dev**

Add a temporary route for visual iteration only (remove before merging):
```php
Route::get('/_dev/pdf-preview', fn () => view('pdf.teaching-schedule-teacher', [...sample data...]));
```
Open in browser to iterate on layout. **Delete this route** before commit.

**Step 4: Commit**

```bash
git add resources/views/pdf/ && git commit -m "Add PDF Blade templates for teacher schedule export"
```

---

## Task 7: Controller + route

**Files:**
- Create: `app/Http/Controllers/Api/Admin/TeachingScheduleExportController.php`
- Modify: `routes/api.php`

**Step 1: Controller**

```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportTeacherSchedulePdfRequest;
use App\Models\Teacher;
use App\Services\TeachingScheduleService;
use Illuminate\Http\Response;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\Enums\Format;

class TeachingScheduleExportController extends Controller
{
    public function __construct(
        private TeachingScheduleService $teachingScheduleService,
    ) {}

    public function teacher(
        ExportTeacherSchedulePdfRequest $request,
        Teacher $teacher,
    ): Response {
        $viewModel = $this->teachingScheduleService->buildTeacherExportViewModel(
            teacher: $teacher,
            academicYearId: $request->validated('academic_year_id'),
            semester: $request->validated('semester'),
        );

        $orientation = $request->orientation();
        $filename = $this->buildFilename($viewModel, $orientation);

        return Pdf::view('pdf.teaching-schedule-teacher', [
                ...$viewModel,
                'orientation' => $orientation,
            ])
            ->format(Format::A4)
            ->{$orientation === 'landscape' ? 'landscape' : 'portrait'}()
            ->name($filename)
            ->inline();
    }

    private function buildFilename(array $viewModel, string $orientation): string
    {
        $teacherCode = $viewModel['teacher']['code'];
        $semester = $viewModel['semester'];
        $year = str_replace('/', '-', $viewModel['academic_year']['name']);

        return "Jadwal-{$teacherCode}-Sem{$semester}-{$year}.pdf";
    }
}
```

**Step 2: Route**

In `routes/api.php`, inside the existing `teaching-schedules` group:

```php
Route::get('/export/teacher/{teacher}', [TeachingScheduleExportController::class, 'teacher'])
    ->middleware('permission:view-schedules');
```

(The outer `auth:sanctum` middleware is already applied to the group.)

**Step 3: Commit**

```bash
git add app/Http/Controllers/Api/Admin/TeachingScheduleExportController.php routes/api.php \
  && git commit -m "Add PDF export endpoint for teacher schedules"
```

---

## Task 8: Feature test

**Files:**
- Create: `tests/Feature/Admin/TeachingScheduleExportTest.php`

**Step 1: Test cases (Pest)**

Cover:

1. `unauthenticated request returns 401`
2. `user without view-schedules permission returns 403`
3. `validation: missing academic_year_id returns 422`
4. `validation: invalid semester returns 422`
5. `successful landscape export returns 200 application/pdf with inline disposition and code-based filename`
6. `successful portrait export returns 200 application/pdf with inline disposition`
7. `non-existent teacher returns 404`
8. `teacher with zero schedules returns 200 with empty grid (no 500)`

**Note for assertion strategy:** assert response headers (`Content-Type: application/pdf`, `Content-Disposition: inline; filename="..."`). Don't assert PDF bytes — that's brittle. Browsershot is exercised in tests; CI must have Chromium available (see Task 12 for VPS setup).

**Step 2: Run**

```bash
php artisan test --filter=TeachingScheduleExportTest
```
Expected: all 8 cases green.

**Step 3: Commit**

```bash
git add tests/Feature/Admin/TeachingScheduleExportTest.php \
  && git commit -m "Add feature test for teacher schedule PDF export endpoint"
```

---

## Task 9: Frontend — export service

**Files (frontend repo `C:\laragon\www\ribath-masjid-hub`):**
- Create: `src/services/api/teachingScheduleExportService.ts`

**Step 1: Service**

```ts
import { apiClient } from '@/lib/apiClient';

export interface ExportTeacherScheduleParams {
  teacherId: string;
  academicYearId: string;
  semester: 1 | 2;
  orientation: 'portrait' | 'landscape';
}

export const exportTeacherSchedulePdf = async (
  params: ExportTeacherScheduleParams,
): Promise<Blob> => {
  const response = await apiClient.get(
    `/teaching-schedules/export/teacher/${params.teacherId}`,
    {
      params: {
        academic_year_id: params.academicYearId,
        semester: params.semester,
        orientation: params.orientation,
      },
      responseType: 'blob',
    },
  );
  return response.data;
};
```

**Step 2: Commit**

```bash
git add src/services/api/teachingScheduleExportService.ts \
  && git commit -m "Add teaching schedule PDF export service"
```

---

## Task 10: Frontend — preview modal component

**Files (frontend repo):**
- Create: `src/pages/schedule/components/PdfPreviewModal.tsx`

**Step 1: Component requirements**

Props:
```ts
interface PdfPreviewModalProps {
  open: boolean;
  onClose: () => void;
  teacherId: string;
  teacherFullName: string;
  teacherCode: string;
  academicYearId: string;
  academicYearName: string;
  semester: 1 | 2;
}
```

Behavior:

1. On open, fetch PDF blob with default orientation `landscape` via `exportTeacherSchedulePdf`.
2. Create object URL via `URL.createObjectURL(blob)`. Cleanup on close + on orientation toggle.
3. Render in `<iframe src={blobUrl}>` filling the modal body.
4. Top toolbar:
   - **Orientation toggle** (segmented control, two options):
     - "Tampilan HP" — portrait icon (`Smartphone`)
     - "Tampilan Cetak" — landscape icon (`Printer`)
   - **Download** button — triggers `<a download={filename}>` click, filename = `Jadwal-{code}-Sem{n}-{academicYearName.replace('/','-')}.pdf`
   - **Close** button (`X`)
5. Loading state: spinner overlay during fetch.
6. Error state: red banner with retry button.
7. Use shadcn `Dialog` component for the modal shell.
8. On orientation change → refetch + re-render iframe.

**Step 2: Visual polish**

- Modal width: `max-w-5xl` on desktop, full-screen on mobile.
- Iframe min-height: `70vh`.
- Toolbar uses theme `primary` color, not hardcoded teal.

**Step 3: Commit**

```bash
git add src/pages/schedule/components/PdfPreviewModal.tsx \
  && git commit -m "Add PDF preview modal with orientation toggle"
```

---

## Task 11: Frontend — wire Export PDF button

**Files (frontend repo):**
- Modify: `src/pages/schedule/components/TeacherScheduleCalendar.tsx`
- Modify: `src/pages/schedule/components/ScheduleTeacherView.tsx` (pass through teacher + active academic year/semester props)

**Step 1: Add button next to Salin**

Inside the Ringkasan card header, alongside the existing Salin button:
- Icon: `FileDown` from lucide-react
- Label: `Export PDF`
- Disabled when:
  - `!navigator.onLine` → tooltip `"Export PDF memerlukan koneksi"`
  - `sortedSchedules.length === 0` → tooltip `"Belum ada jadwal untuk diexport"`
- onClick → opens `<PdfPreviewModal />`

**Step 2: Plumb required props**

`ScheduleTeacherView` already has `selectedTeacher`. Pass active academic year + semester from the page-level `useActiveTahunAjaran` hook (already used elsewhere in this codebase) down to `TeacherScheduleCalendar`.

**Step 3: Online status**

Use a small `useOnlineStatus()` hook (create in `src/hooks/useOnlineStatus.ts` if it doesn't exist):

```ts
import { useEffect, useState } from 'react';

export const useOnlineStatus = () => {
  const [online, setOnline] = useState(navigator.onLine);
  useEffect(() => {
    const onUp = () => setOnline(true);
    const onDown = () => setOnline(false);
    window.addEventListener('online', onUp);
    window.addEventListener('offline', onDown);
    return () => {
      window.removeEventListener('online', onUp);
      window.removeEventListener('offline', onDown);
    };
  }, []);
  return online;
};
```

**Step 4: Commit**

```bash
git add src/pages/schedule/components/ src/hooks/useOnlineStatus.ts \
  && git commit -m "Wire Export PDF button into per-ustadz schedule view"
```

---

## Task 12: VPS prep for production

**Where:** `103.157.97.233`, user `ak_rocks`. Use the SSH access already established (see VPS reference memory).

**Step 1: Verify Node + npm on VPS**

```bash
node --version && npm --version
```
If Node < 18, install Node 20 LTS via NodeSource.

**Step 2: Install Chromium dependencies**

Browsershot's bundled Chromium (via puppeteer) needs system libs:
```bash
sudo apt update
sudo apt install -y \
    libnss3 libatk-bridge2.0-0 libdrm2 libxkbcommon0 libxcomposite1 \
    libxdamage1 libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 \
    libasound2 libatspi2.0-0
```

**Step 3: Update Laravel deploy.sh to install puppeteer**

Modify `/srv/www/ribath-backend/scripts/deploy.sh` to run `npm ci` in the release dir before swapping the symlink. (Backend repo currently doesn't use npm; this is a new dependency. Add the step idempotently — only if `package.json` exists.)

Snippet to add after composer install:
```bash
if [ -f "$RELEASE_DIR/package.json" ]; then
    echo "[X/Y] Installing Node dependencies (Browsershot Chromium)..."
    cd "$RELEASE_DIR"
    npm ci --production
fi
```

**Step 4: Set Browsershot env vars in shared .env**

Add to `/srv/www/ribath-backend/shared/env/.env`:
```
BROWSERSHOT_NODE_BINARY=/usr/bin/node
BROWSERSHOT_NPM_BINARY=/usr/bin/npm
```

**Step 5: Smoke test on VPS post-deploy**

```bash
cd /srv/www/ribath-backend/current
php artisan tinker --execute="Spatie\Browsershot\Browsershot::html('<h1>OK</h1>')->save('/tmp/test.pdf'); echo file_exists('/tmp/test.pdf') ? 'OK' : 'FAIL';"
```

---

## Task 13: Manual QA checklist (production)

Run on `https://ribath.hyperscore.cloud/jadwal` after both repos deployed:

- [ ] Per Ustadz tab → pick a teacher with schedules → Export PDF button visible
- [ ] Click Export PDF → modal opens with landscape preview within ~3 seconds
- [ ] Toggle to portrait → preview reflows to grouped-by-day list
- [ ] Toggle back to landscape → grid restored
- [ ] Download → file saved as `Jadwal-{code}-Sem{n}-{year}.pdf`, opens correctly in OS PDF viewer
- [ ] PDF header shows correct school info from `schools` table
- [ ] PDF footer shows current Indonesian-formatted date/time in WIB
- [ ] Pick a teacher with **zero** schedules → button disabled with tooltip
- [ ] Disconnect network (DevTools offline) → button disabled with offline tooltip
- [ ] Log in as user without `view-schedules` permission → button hidden / 403 if hit directly
- [ ] Render time on a typical teacher (~12 schedules) under 5s end-to-end

---

## Acceptance criteria

1. Backend tests green — `php artisan test --filter=TeachingScheduleExportTest` returns all green; existing test suite unaffected.
2. Frontend lints + typechecks clean — `npm run lint` and `npx tsc --noEmit` exit 0.
3. Both orientations render correctly in production with real teacher data.
4. Permissions enforced — unauth → 401, no-permission → 403.
5. Endpoint reusable: a future `class-level` PDF, `student rapor` PDF, etc., follows the same controller/Blade/test pattern with no foundational rework.
6. No emoji or hardcoded school name in any code or PDF output (generic-app principle).

---

## Open follow-ups (not in this plan)

1. **Schools logo column** — `ALTER TABLE schools ADD COLUMN logo_path TEXT NULL` + admin upload UI. Update Blade to prefer `school.logo_path` over default placeholder.
2. **Bulk export** — single PDF with all teachers for the active semester, page-broken per teacher.
3. **PDF caching** — cache rendered PDFs keyed by `teacher_id + academic_year_id + semester + orientation + content hash`, invalidate on schedule mutations.
4. **Class-level PDF** — same Blade pattern, axis transposed (subjects per teacher per day for one class).
5. **Santri-facing PWA view** — read-only schedule for the santri's class; downstream of class-level endpoint.
