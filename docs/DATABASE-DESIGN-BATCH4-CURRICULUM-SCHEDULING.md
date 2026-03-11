# Database Design: Curriculum & Scheduling (Batch 4)

> Comprehensive database architecture for the curriculum and teaching schedule system.
> This document serves as the source of truth for all table structures, relationships,
> naming conventions, and design decisions made during Batch 4 of the Supabase-to-Laravel migration.

---

## Table of Contents

1. [Design Principles](#1-design-principles)
2. [Existing Tables (Dependencies)](#2-existing-tables-dependencies)
3. [New Tables](#3-new-tables)
4. [Schema Modifications to Existing Tables](#4-schema-modifications-to-existing-tables)
5. [Entity Relationship Diagram](#5-entity-relationship-diagram)
6. [Naming Conventions](#6-naming-conventions)
7. [Value Enumerations](#7-value-enumerations)
8. [Migration from Supabase](#8-migration-from-supabase)
9. [Future-Proofing](#9-future-proofing)

---

## 1. Design Principles

### 1.1 Lookup tables over hardcoded enums

Configurable data (time slots, class levels, subject categories) lives in database tables,
not in CHECK constraints or application constants. Each school defines their own set.
This allows the system to serve any pesantren without code changes.

**Pattern:** `class_levels`, `time_slots`, `subject_categories` all follow the same shape:
- `school_id` FK for multi-tenancy
- `slug` / `code` for machine-readable keys
- `label` / `name` for display
- `sort_order` for UI ordering
- `is_active` for soft visibility control

### 1.2 Foreign keys over loose strings

References between tables use proper FKs, not string matching.
A teaching schedule references `class_level_id` (UUID FK), not `class_level` (string slug).
This prevents orphaned references and enables efficient joins.

### 1.3 English naming throughout

- Table names: English, plural, snake_case (`teaching_schedules`, `subject_books`)
- Column names: English, snake_case (`day_of_week`, `sessions_per_week`)
- Stored values: English where possible (`monday`, `after_fajr`)
- Domain-specific Islamic terms kept as transliteration (`fajr`, `dhuhr`, `asr`, `maghrib`, `isha`, `tahfidz`, `takhassus`)

### 1.4 Multi-tenancy via school_id

Every table includes `school_id` FK to `schools`. All queries must scope by school.
This allows multiple pesantren to share the same database instance.

### 1.5 UUID primary keys

All new tables use UUID PKs (via Laravel `HasUuids` trait), consistent with existing
`students`, `teachers`, `class_levels`, etc. Exception: `subject_categories` uses integer
auto-increment to match the Supabase `fann_categories` table (frontend stores the ID as a number).

---

## 2. Existing Tables (Dependencies)

These tables already exist in the local database and are referenced by the new tables.

### 2.1 schools

```
schools
├── id           UUID, PK
├── name         VARCHAR, NOT NULL
├── address      TEXT, nullable
├── phone        VARCHAR, nullable
├── email        VARCHAR, nullable
├── is_active    BOOLEAN, NOT NULL
├── created_at   TIMESTAMP
├── updated_at   TIMESTAMP
```

### 2.2 class_levels

```
class_levels
├── id           UUID, PK
├── school_id    UUID, FK → schools.id, NOT NULL
├── slug         VARCHAR, NOT NULL (e.g. "tamhidi", "ibtida_1")
├── label        VARCHAR, NOT NULL (e.g. "Tamhidi", "Ibtida 1")
├── category     VARCHAR, NOT NULL (academic, tahfidz, takhassus)
├── sort_order   SMALLINT, NOT NULL
├── is_active    BOOLEAN, NOT NULL
├── created_at   TIMESTAMP
├── updated_at   TIMESTAMP
│
├── UNIQUE(school_id, slug)
```

### 2.3 teachers

```
teachers
├── id           UUID, PK
├── school_id    UUID, FK → schools.id, NOT NULL
├── user_id      BIGINT, FK → users.id, nullable
├── code         VARCHAR, NOT NULL (e.g. "AK", "UAS")
├── full_name    VARCHAR, NOT NULL
├── status       VARCHAR, NOT NULL (active, on_leave, inactive)
├── email        VARCHAR, nullable
├── phone        VARCHAR, nullable
├── notes        TEXT, nullable
├── created_at   TIMESTAMP
├── updated_at   TIMESTAMP
├── deleted_at   TIMESTAMP, nullable (SoftDeletes)
```

### 2.4 students

```
students
├── id                    UUID, PK
├── school_id             UUID, FK → schools.id, nullable
├── registration_id       UUID, FK → registrations.id, nullable
├── guardian_user_id      BIGINT, FK → users.id, nullable
├── user_id               BIGINT, FK → users.id, nullable
├── full_name             VARCHAR, NOT NULL
├── birth_place           VARCHAR, nullable
├── birth_date            DATE, NOT NULL
├── gender                VARCHAR, NOT NULL
├── program               VARCHAR, NOT NULL
├── class_level           VARCHAR, nullable ← LOOSE STRING (to be supplemented with FK)
├── status                VARCHAR, NOT NULL (active, graduated, transferred, withdrawn)
├── entry_date            DATE, NOT NULL
├── address               TEXT, nullable
├── photo_url             VARCHAR, nullable
├── notes                 TEXT, nullable
├── profile_completed_at  TIMESTAMP, nullable
├── created_at            TIMESTAMP
├── updated_at            TIMESTAMP
├── deleted_at            TIMESTAMP, nullable (SoftDeletes)
```

---

## 3. New Tables

### 3.1 academic_years

Scoping entity for all academic data. Every schedule, and later grades and attendance,
references an academic year. Only one academic year per school should be active at a time.

```sql
CREATE TABLE academic_years (
    id              UUID PRIMARY KEY,
    school_id       UUID NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    name            VARCHAR(20) NOT NULL,          -- "2025/2026"
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    active_semester SMALLINT NOT NULL DEFAULT 1,   -- 1 or 2
    is_active       BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,

    CONSTRAINT chk_academic_year_semester CHECK (active_semester IN (1, 2)),
    CONSTRAINT chk_academic_year_dates CHECK (end_date > start_date),
    UNIQUE (school_id, name)
);
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key |
| `school_id` | UUID FK | Multi-tenancy scope |
| `name` | VARCHAR(20) | Display format: "2025/2026" |
| `start_date` | DATE | Academic year begins |
| `end_date` | DATE | Academic year ends |
| `active_semester` | SMALLINT | Currently active semester (1 or 2) |
| `is_active` | BOOLEAN | Only one active per school |

**Design notes:**
- `active_semester` lives here (not on the schedule) because it represents the school's
  current operational state. The admin toggles this when the semester changes.
- `is_active` means "this is the current academic year." Schedules exist for both semesters,
  but `active_semester` determines which one the UI shows by default.
- Constraint ensures only valid semesters and valid date ranges.

### 3.2 time_slots

Configurable time slot definitions per school. Replaces the hardcoded CHECK constraint
in the Supabase `jadwal_mengajar` table. Each school defines their own daily rhythm.

```sql
CREATE TABLE time_slots (
    id          UUID PRIMARY KEY,
    school_id   UUID NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    code        VARCHAR(30) NOT NULL,     -- machine key: "after_fajr", "07:00-08:00"
    label       VARCHAR(50) NOT NULL,     -- display: "Ba'da Subuh", "07:00 - 08:00"
    type        VARCHAR(15) NOT NULL,     -- "prayer_based" or "fixed_clock"
    start_time  TIME,                     -- approximate for prayer-based, exact for fixed
    end_time    TIME,                     -- nullable for prayer-based
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,

    CONSTRAINT chk_time_slot_type CHECK (type IN ('prayer_based', 'fixed_clock')),
    UNIQUE (school_id, code)
);
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key |
| `school_id` | UUID FK | Each school has its own slots |
| `code` | VARCHAR(30) | Machine-readable key used in queries and API |
| `label` | VARCHAR(50) | Human-readable display name |
| `type` | VARCHAR(15) | `prayer_based` (times shift daily) or `fixed_clock` (exact times) |
| `start_time` | TIME | Approximate start for prayer-based, exact for fixed |
| `end_time` | TIME | Approximate end for prayer-based, exact for fixed |
| `sort_order` | SMALLINT | Controls row order in the schedule grid |
| `is_active` | BOOLEAN | Soft visibility control |

**Design notes:**
- `start_time` and `end_time` are nullable because prayer-based slots don't have fixed times.
  The stored values are approximations for display purposes only.
- `code` is the stable identifier used in API requests and database queries.
  `label` can be changed without affecting data integrity.
- `sort_order` determines the vertical order in the schedule grid (top = earliest slot).
- Future enhancement: link prayer-based slots to an automatic prayer time API.

**Default data for Ribath Masjid Riyadh:**

| code | label | type | start_time | end_time | sort_order |
|------|-------|------|------------|----------|------------|
| `after_fajr` | Ba'da Subuh | prayer_based | 05:45 | 06:45 | 1 |
| `07:00-08:00` | 07:00 - 08:00 | fixed_clock | 07:00 | 08:00 | 2 |
| `08:00-09:00` | 08:00 - 09:00 | fixed_clock | 08:00 | 09:00 | 3 |
| `after_dhuhr` | Ba'da Dhuhur | prayer_based | 12:30 | 13:30 | 4 |
| `after_asr` | Ba'da Ashar | prayer_based | 15:30 | 16:30 | 5 |
| `after_maghrib` | Ba'da Maghrib | prayer_based | 18:00 | 19:00 | 6 |
| `after_isha` | Ba'da Isya | prayer_based | 19:30 | 20:30 | 7 |

### 3.3 subject_categories

Classification of Islamic knowledge fields. Each book belongs to one category.
Replaces the Supabase `fann_categories` table.

```sql
CREATE TABLE subject_categories (
    id          BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
    school_id   UUID NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    slug        VARCHAR(50) NOT NULL,     -- "nahwu", "fiqh", "tafsir"
    name        VARCHAR(100) NOT NULL,    -- "Nahwu", "Fiqh", "Tafsir"
    color       VARCHAR(50) NOT NULL DEFAULT 'bg-gray-100',  -- Tailwind CSS class
    description TEXT,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMP,
    updated_at  TIMESTAMP,

    UNIQUE (school_id, slug)
);
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Auto-increment integer PK (matches Supabase frontend expectations) |
| `school_id` | UUID FK | Multi-tenancy scope |
| `slug` | VARCHAR(50) | URL-safe identifier |
| `name` | VARCHAR(100) | Display name |
| `color` | VARCHAR(50) | Tailwind CSS background class for UI chips |
| `description` | TEXT | Optional description of the subject field |
| `sort_order` | SMALLINT | Display order in dropdown and filter chips |
| `is_active` | BOOLEAN | Soft visibility control |

**Design notes:**
- Uses integer PK instead of UUID. The Supabase `fann_categories` table uses `integer`.
  The frontend `SubjectCategory` type uses `id: number`. Changing to UUID adds no benefit.
- Table named `subject_categories` (not `fann_categories`) because "fann" is Arabic jargon
  that a general developer wouldn't understand. The UI can still display "Fann" as a label.

### 3.4 subject_books

Islamic textbooks (kitab) used as teaching materials. Each book belongs to one subject
category and can be assigned to multiple class levels and semesters.
Replaces the Supabase `kitab` table.

```sql
CREATE TABLE subject_books (
    id                    UUID PRIMARY KEY,
    school_id             UUID NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    subject_category_id   BIGINT NOT NULL REFERENCES subject_categories(id),
    title                 VARCHAR(100) NOT NULL,    -- "Jurumiyyah", "Safinatun Najah"
    class_levels          JSONB NOT NULL,            -- ["tamhidi", "ibtida_1"]
    semesters             JSONB NOT NULL,            -- [1, 2]
    sessions_per_week     SMALLINT NOT NULL DEFAULT 1,  -- 1-7
    description           TEXT,
    is_active             BOOLEAN NOT NULL DEFAULT true,
    created_at            TIMESTAMP,
    updated_at            TIMESTAMP
);
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key |
| `school_id` | UUID FK | Multi-tenancy scope |
| `subject_category_id` | BIGINT FK | References `subject_categories.id` |
| `title` | VARCHAR(100) | Book name (e.g. "Jurumiyyah") |
| `class_levels` | JSONB | Array of class_level slugs where this book is taught |
| `semesters` | JSONB | Array of semester numbers [1], [2], or [1,2] |
| `sessions_per_week` | SMALLINT | How many teaching sessions per week (1-7) |
| `description` | TEXT | Optional description |
| `is_active` | BOOLEAN | Soft visibility control |

**Design notes:**
- `class_levels` stored as JSON array of slugs (e.g. `["tamhidi", "ibtida_1"]`).
  A junction table is unnecessary for this scale (<100 books, <15 class levels).
- `sessions_per_week` (not `classes_per_week`) avoids confusion with `class_levels`.
  "Session" means one teaching occurrence.
- Supabase legacy fields (`fann_old`, `semester` integer, `prerequisites` array) are
  intentionally dropped. Clean slate.
- FK column named `subject_category_id` (matches the table it points to), not
  `fann_category_id` (which was the Supabase convention).

### 3.5 teaching_schedules

Weekly timetable entries. Links a class level, time slot, book, and teacher for a
specific day within an academic year and semester.
Replaces the Supabase `jadwal_mengajar` table.

```sql
CREATE TABLE teaching_schedules (
    id                UUID PRIMARY KEY,
    school_id         UUID NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    academic_year_id  UUID NOT NULL REFERENCES academic_years(id),
    semester          SMALLINT NOT NULL,            -- 1 or 2
    day_of_week       VARCHAR(10) NOT NULL,         -- "monday", "tuesday", etc.
    time_slot_id      UUID NOT NULL REFERENCES time_slots(id),
    class_level_id    UUID NOT NULL REFERENCES class_levels(id),
    subject_book_id   UUID NOT NULL REFERENCES subject_books(id),
    teacher_id        UUID NOT NULL REFERENCES teachers(id),
    is_active         BOOLEAN NOT NULL DEFAULT true,
    created_at        TIMESTAMP,
    updated_at        TIMESTAMP,

    CONSTRAINT chk_schedule_semester CHECK (semester IN (1, 2)),
    CONSTRAINT chk_schedule_day CHECK (day_of_week IN (
        'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday'
    )),
    UNIQUE (school_id, academic_year_id, semester, day_of_week, time_slot_id, class_level_id)
);
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary key |
| `school_id` | UUID FK | Multi-tenancy scope |
| `academic_year_id` | UUID FK | Scopes schedule to a specific academic year |
| `semester` | SMALLINT | 1 or 2 |
| `day_of_week` | VARCHAR(10) | English day name (monday...sunday) |
| `time_slot_id` | UUID FK | References configurable `time_slots.id` |
| `class_level_id` | UUID FK | References `class_levels.id` |
| `subject_book_id` | UUID FK | References `subject_books.id` |
| `teacher_id` | UUID FK | References `teachers.id` |
| `is_active` | BOOLEAN | Soft delete (deactivated schedules stay for historical reference) |

**Design notes:**
- All references are proper FKs: `academic_year_id`, `time_slot_id`, `class_level_id`,
  `subject_book_id`, `teacher_id`. No loose strings.
- `day_of_week` stores English day names (`monday`, `tuesday`, ...). The frontend
  uses the same English values internally and maps to Indonesian labels ("Senin", "Selasa")
  only in JSX display via a `DAYS_OF_WEEK` constant with `{ value, label }` pairs.
- Unique constraint prevents double-booking a class slot. Teacher conflicts are validated
  in the service layer (not a DB constraint) because the error message needs to be descriptive:
  "Teacher Ahmad is already teaching Ibtida 2 at the same time."
- `semester` is on this table (not only on `academic_years`) because schedules exist for
  both semesters independently. The academic year's `active_semester` determines the default view.
- `is_active = false` means the schedule entry is deactivated but not deleted.
  Future attendance and grade records can still reference it for historical queries.

**Indexes for common queries:**
```sql
CREATE INDEX idx_schedules_year_semester ON teaching_schedules (academic_year_id, semester);
CREATE INDEX idx_schedules_class ON teaching_schedules (class_level_id);
CREATE INDEX idx_schedules_teacher ON teaching_schedules (teacher_id);
```

---

## 4. Schema Modifications to Existing Tables

### 4.1 Add `class_level_id` FK to `students`

```sql
ALTER TABLE students
    ADD COLUMN class_level_id UUID REFERENCES class_levels(id);

-- Backfill from existing string values
UPDATE students s
SET class_level_id = cl.id
FROM class_levels cl
WHERE s.class_level = cl.slug
  AND s.school_id = cl.school_id
  AND s.class_level IS NOT NULL;
```

**Design notes:**
- Non-breaking change. The existing `class_level` VARCHAR column stays for backward
  compatibility. New code should use `class_level_id`.
- Backfill runs once during migration, matching slug values.
- Eventually `class_level` (string) column can be dropped when all code uses the FK.

---

## 5. Entity Relationship Diagram

```
schools ──────────────────────────────────────────────────────────
  │                                                              │
  ├── academic_years (1:M)                                       │
  │     └── teaching_schedules (1:M)                             │
  │                                                              │
  ├── time_slots (1:M)                                           │
  │     └── teaching_schedules (1:M)                             │
  │                                                              │
  ├── class_levels (1:M)                                         │
  │     ├── teaching_schedules (1:M)                             │
  │     └── students (1:M) via class_level_id                    │
  │                                                              │
  ├── subject_categories (1:M)                                   │
  │     └── subject_books (1:M)                                  │
  │           └── teaching_schedules (1:M)                       │
  │                                                              │
  ├── teachers (1:M)                                             │
  │     └── teaching_schedules (1:M)                             │
  │                                                              │
  └── students (1:M)                                             │
────────────────────────────────────────────────────────────────────

teaching_schedules (central join table):
  → academic_year_id  FK → academic_years.id
  → time_slot_id      FK → time_slots.id
  → class_level_id    FK → class_levels.id
  → subject_book_id   FK → subject_books.id
  → teacher_id        FK → teachers.id

Future downstream tables:
  attendance_records   → teaching_schedule_id FK
  student_grades       → teaching_schedule_id FK + subject_book_id FK
  student_book_progress → subject_book_id FK
```

---

## 6. Naming Conventions

### 6.1 Table names

| Convention | Example |
|-----------|---------|
| English | `teaching_schedules` not `jadwal_mengajar` |
| Plural | `subject_books` not `subject_book` |
| snake_case | `academic_years` not `academicYears` |
| Descriptive compound | `subject_books` not `books` (avoids ambiguity) |

### 6.2 Column names

| Convention | Example |
|-----------|---------|
| English snake_case | `day_of_week` not `hari` |
| FK matches target table | `subject_category_id` → `subject_categories.id` |
| Boolean prefix | `is_active` not `active` |
| Avoid abbreviations | `sessions_per_week` not `freq_per_wk` |

### 6.3 Stored values

| Type | Convention | Examples |
|------|-----------|----------|
| Day names | English lowercase | `monday`, `tuesday`, `wednesday` |
| Time slot codes | English pattern | `after_fajr`, `07:00-08:00` |
| Class categories | English where translatable | `academic` (not `akademik`) |
| Islamic terms | Standard transliteration | `tahfidz`, `takhassus`, `fajr`, `dhuhr` |
| Status values | English | `active`, `inactive`, `prayer_based`, `fixed_clock` |

### 6.4 Frontend ↔ Backend naming alignment

Frontend and backend use the **same English names**. No field mapper translation layer. The API response shape matches the frontend TypeScript types directly.

| Name | Used in backend | Used in frontend | Notes |
|------|----------------|-----------------|-------|
| `day_of_week` | DB column, API field | TS type field, form field | Stored: `monday`, `tuesday`, ... |
| `time_slot_id` | FK column, API field | TS type field, form select value | UUID reference |
| `class_level_id` | FK column, API field | TS type field, form select value | UUID reference |
| `subject_book_id` | FK column, API field | TS type field, form select value | UUID reference |
| `teacher_id` | FK column, API field | TS type field, form select value | UUID reference |
| `academic_year_id` | FK column, API field | TS type field, filter param | UUID reference |
| `title` | `subject_books.title` | `SubjectBook.title` | Book name |
| `subject_category_id` | FK column | `SubjectBook.subject_category_id` | Integer reference |
| `sessions_per_week` | `subject_books` column | `SubjectBook.sessions_per_week` | 1-7 |
| `description` | nullable text column | `SubjectBook.description` | Optional |

Indonesian appears only in **UI display labels** (JSX string literals, toast messages, form placeholders) — never in variable names, type fields, or API contracts.

---

## 7. Value Enumerations

### 7.1 Days of week

Stored in `teaching_schedules.day_of_week`:

| Value | Indonesian display |
|-------|-------------------|
| `monday` | Senin |
| `tuesday` | Selasa |
| `wednesday` | Rabu |
| `thursday` | Kamis |
| `friday` | Jumat |
| `saturday` | Sabtu |
| `sunday` | Ahad |

### 7.2 Time slot types

Stored in `time_slots.type`:

| Value | Description |
|-------|-------------|
| `prayer_based` | Tied to Islamic prayer times; actual clock time shifts daily/seasonally |
| `fixed_clock` | Fixed start and end times |

### 7.3 Semesters

Stored in `teaching_schedules.semester` and `academic_years.active_semester`:

| Value | Description |
|-------|-------------|
| `1` | First semester (odd / ganjil) |
| `2` | Second semester (even / genap) |

### 7.4 Class level categories

Stored in `class_levels.category`:

| Value | Description |
|-------|-------------|
| `academic` | Standard Islamic studies curriculum |
| `tahfidz` | Quran memorization program |
| `takhassus` | Advanced specialization program |

---

## 8. Migration from Supabase

### 8.1 Table name mapping

| Supabase table | Laravel table | Notes |
|---------------|---------------|-------|
| `tahun_ajaran` | `academic_years` | New table, different structure |
| (hardcoded CHECK) | `time_slots` | New table, was hardcoded before |
| `fann_categories` | `subject_categories` | Renamed, same structure |
| `kitab` | `subject_books` | Renamed, cleaned up columns |
| `jadwal_mengajar` | `teaching_schedules` | All FKs, no loose strings |

### 8.2 Dropped Supabase columns (not migrated)

| Table | Column | Reason |
|-------|--------|--------|
| `kitab` | `fann_old` | Legacy field, replaced by `subject_category_id` FK |
| `kitab` | `semester` (integer) | Replaced by `semesters` JSON array |
| `kitab` | `prerequisites` | Unused in current schedule workflow |
| `kitab` | `pesantren_id` | Replaced by `school_id` |
| `jadwal_mengajar` | `waktu_aktual` | Unused in frontend |
| `jadwal_mengajar` | `waktu_relatif` | Unused in frontend |
| `jadwal_mengajar` | `pesantren_id` | Replaced by `school_id` |

### 8.3 Data migration strategy

For initial launch, no data migration is needed. The Supabase schedule system was never
used in production. The admin creates fresh data in the Laravel system.

If data migration is needed later:
1. Export Supabase `fann_categories` → import into `subject_categories`
2. Export Supabase `kitab` → import into `subject_books` (map `fann_category_id` → `subject_category_id`)
3. No schedule data to migrate (never used)

---

## 9. Future-Proofing

### 9.1 Tables that will reference this schema

| Future table | References | Batch |
|-------------|-----------|-------|
| `attendance_records` | `teaching_schedule_id` FK | Batch 5 |
| `student_grades` | `teaching_schedule_id` FK, `subject_book_id` FK | Batch 6 |
| `student_book_progress` | `subject_book_id` FK | Batch 6 |
| `academic_calendar_events` | `academic_year_id` FK | Batch 4 (deferred) |
| `tahfidz_schedules` | `time_slot_id` FK, `academic_year_id` FK | Batch 7 |

### 9.2 Prepared for future features

| Feature | How this design supports it |
|---------|---------------------------|
| **Attendance** | `attendance_records.teaching_schedule_id` → knows class, book, teacher, day, time |
| **Grades** | `student_grades.teaching_schedule_id` + `subject_book_id` → grades per book per student |
| **Year transition** | Create new `academic_year`, optionally copy schedules, toggle `is_active` |
| **Semester switch** | Update `academic_years.active_semester` → UI filters automatically |
| **Class promotion** | Update `students.class_level_id` → student sees new class schedule |
| **Teacher reassignment** | Update `teaching_schedules.teacher_id` → historical records preserved |
| **Reports by year** | JOIN through `academic_year_id` — no string parsing |
| **Different school rhythms** | Each school defines own `time_slots` — no code changes needed |
| **Adding new classes** | Admin creates a `class_level` → immediately available in schedule grid |

### 9.3 Migration order (dependency chain)

```
1. academic_years       (no dependencies)
2. time_slots           (no dependencies)
3. subject_categories   (no dependencies)
4. subject_books        (depends on: subject_categories)
5. teaching_schedules   (depends on: academic_years, time_slots, class_levels, subject_books, teachers)
6. students ALTER       (depends on: class_levels — already exists)
```

---

## Appendix: Laravel Model Quick Reference

| Model | Table | PK Type | Traits |
|-------|-------|---------|--------|
| `AcademicYear` | `academic_years` | UUID | HasFactory, HasUuids |
| `TimeSlot` | `time_slots` | UUID | HasFactory, HasUuids |
| `SubjectCategory` | `subject_categories` | BIGINT | HasFactory |
| `SubjectBook` | `subject_books` | UUID | HasFactory, HasUuids |
| `TeachingSchedule` | `teaching_schedules` | UUID | HasFactory, HasUuids |
