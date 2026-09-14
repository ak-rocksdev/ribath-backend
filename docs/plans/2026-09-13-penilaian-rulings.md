# Penilaian — keputusan implementasi, temuan tertunda, dan catatan deploy

Dicatat dari ledger subagent-driven-development (plan: `docs/plans/2026-09-13-penilaian-implementation.md`). Setiap keputusan ditulis sebagai: apa yang diputuskan — alasan — biaya jika keliru.

## Keputusan (rulings)

- Ruling R1: T3 implements `has_grades` through a private method `semesterHasRecordedGrades()` that returns false (no Schema::hasTable); T5 replaces its body with the real query — avoids runtime schema probing — cost if wrong: trivial edit.
- Ruling R2: T5's bulk upsert accepts only `percent`-scale manual factors (manual_once + end_of_semester_bulk with score_scale=percent); a `level_1_4` factor code in T5 → 422 "Faktor ini diinput lewat halaman Adab & Keaktifan." The T5 grid GET still returns level_1_4 factors (read-only display). T7 then adds level handling to the same endpoint (integer = level) — keeps one meaning per factor from day one — cost if wrong: small endpoint change.
- Ruling R3: T6 defines `FactorScoreContext` (readonly: AcademicSemester $academicSemester, string $academicYearId, int $semester, ?string $classLevelId, string $subjectBookId, Collection<Student> $students) and `FactorScore` (readonly: ?float $score, ?string $missingReason). Interface `FactorScoreProvider { supports(GradingFactor): bool; scoresFor(GradingFactor, FactorScoreContext): array<string studentId, FactorScore> }`. Registry returns FactorScore(null, null) for unsupported factors — gives T15 a place for "Target belum diset" — cost if wrong: interface tweak in T6 + providers.
- Ruling R4: recap `source` derives from input_type: manual_once|end_of_semester_bulk → manual; manual_periodic → tugas; auto_from_attendance → absensi; auto_from_log → hafalan — cost if wrong: label only.
- Ruling R5: soft-deletable tables with a natural key (class_sessions, memorization_targets) get NO full unique constraint; instead a partial unique index `WHERE deleted_at IS NULL` created with DB::statement (valid on SQLite and PostgreSQL, precedent: 2026_06_30 teaching_schedules migration) plus a service-level check returning 422 — cost if wrong: migration edit before deploy.
- Ruling R6: T14 creates `App\Services\Akademik\Calculation\MemorizationFactorCalculator` with only `calculateTargetAchievement(?float targetPages, float newPages): ?float` (capped 100, null without target) and uses it in progressForStudent; T15 extends the same class with quality/review and `calculate()` — one formula source from day one — cost if wrong: trivial move.
- Task 2: Ruling: Important "midterm validated per present pair, not only when all three set" — implementation matches the controller's own dispatch resolution ("validate only the pairs that are present"); code stands — cost if wrong: slightly stricter partial updates.
- Task 3: Ruling: grading_templates / grading_factors / grading_template_factors carry no created_by/updated_by — spec §3.6 requires them on grade tables; these are configuration rows and the brief's column list omits them — cost if wrong: no record of who changed weights (add 2 columns later).
- Task 5: Ruling: gradable subjects returned one per (class, kitab) with a `teachers` list instead of per (class, kitab, teacher) — avoids duplicate kitab in the selector; teacher is not part of a grade's key — cost if wrong: response shape tweak.
- Task 5: Ruling: accept unrequested guard "kitab with grades cannot be deleted (422)" — prevents FK 500 — cost if wrong: admins must clear grades before deleting a kitab.
- Task 5: Ruling: pull Minor "corrupted draft shape crashes grid" and Minor "no active AY / AY fetch error looks like infinite loading" into fix round 1 — both live in shared utilities (gradeGridDraft, AcademicSemesterSelector) reused by Tasks 7/8/10/13/14 — cost if wrong: slightly larger fix round.
- Task 8: Ruling: task-score grid must reuse useDraftedGradeGrid; implementer adds an optional request-builder parameter to the hook (default = current grade payload) rather than copying the flow — cost if wrong: small hook API change.
- Task 8: Ruling: tasks dated before a student's entry_date are not expected for that student (excluded from average and unscored check; grid shows "Belum masuk") — mirrors spec G16 for sessions; otherwise late students could never complete the Tugas factor — cost if wrong: a late student's Tugas average ignores earlier tasks.
- Task 9: Ruling: frontend "masuk setelah UTS" check must use a data flag (`is_midterm_exam` added to recap factor rows) instead of `factor.code === 'uts'` — spec §6 data-driven factors — cost if wrong: one extra payload field.
- Task 10: Ruling: recording a Pertemuan requires a status for every expected student exactly once (missing → 422 keyed by student_id); frontend prefills "Hadir" — keeps "Pertemuan tercatat" meaning every expected santri has a status, so the absensi denominator is well defined — cost if wrong: can't save partial attendance.
- Task 10: Ruling: a duplicate non-deleted session for schedule+date → 422 (edit via PUT attendances); cancelling a held session keeps its attendance rows but they are ignored — cost if wrong: small service change.
- Task 10: Ruling: the entry-date rule for sessions reuses Task 8's TaskExpectationRule semantics (generalise the class name rather than copy) — cost if wrong: rename only.
- Task 10: Ruling: accept narrowing — only ACTIVE expected santri must have a status; non-active santri optional — spec: non-active santri never block — cost if wrong: small validation change.
- Task 10: Ruling: semester date range applies to all users (not only super_admin) — sessions belong to a semester — cost if wrong: none expected.
- Task 10: Ruling: pull Minor "frontend today uses device TZ" and Minor "cancelling existing session re-checks live schedule" into fix round 1 — both are date-rule correctness in the same policy — cost if wrong: slightly larger fix.
- Task 10: Ruling: super_admin override warning only for future dates, not today (reviewer ⚠️) — matches backend flag; recording today is normal — cost if wrong: one condition.
- Task 10: Ruling: frontend gate widened to `npx vitest run src/test/grading src/test/attendance` (tests stay in src/test/attendance) — cost if wrong: none.
- Task 11: Ruling: alert enumeration starts at max(semester start, schedule created_at Jakarta date) — a schedule created mid-semester isn't expected before it existed — cost if wrong: alerts miss pre-creation dates.
- Task 11: Ruling: libur massal lets non-super_admin cancel FUTURE dates (planning holidays) but keeps the 14-day past window; range ≤ 62 days, active AY+semester only, clamped to semester — cost if wrong: small policy tweak.
- Task 11: Ruling: pull trivial Minor "third copy of parseIsoDate in classSessionSchema.ts" into fix round — cost if wrong: none.
- Task 12: Ruling: sessions without an attendance row for the student are not counted; missing reasons 'Belum ada pertemuan tercatat' / 'Hanya sakit/izin'; one shared tally used by provider and attendance recap — cost if wrong: wording/denominator tweak (provisional rule anyway).
- Task 13: Ruling: Tahfizh stays class-scoped like every other kitab — GradableSubjectService adds (class, Tahfizh kitab) pairs for each class having ≥1 santri with a non-deleted Target Hafalan in that semester; the grid/recap student list for a Tahfizh-template kitab = the class's santri who have a target (one method used by grid, recap and providers) — keeps the Kelas→Kitab picker and every service uniform; plan's "any class when class_level_id omitted" dropped — cost if wrong: add a cross-class view later.
- Task 13: Ruling: accept "target update cannot change student or semester" (delete + recreate instead) — keeps the natural key stable — cost if wrong: small endpoint change.
- Task 13: Ruling: pull trivial Minor "assertNoExistingTarget not school-scoped" into fix round — cost if wrong: none.
- Task 14: Ruling: memorization logs allowed for any active santri of the school (target not required — spec: log without target → target factor NULL "Target belum diset"); log_date ≤ Jakarta business today and inside semester dates when set — cost if wrong: validation tweak.
- Task 14: Ruling: pull Minor "server-422 field mapping duplicated ~40 lines between quick-entry form and edit dialog" into fix round (verbatim duplication) — cost if wrong: none.
- Task 15: Ruling: target set but zero new pages → Pencapaian Target = 0 (a real score, not NULL); missing reasons 'Target belum diset' / 'Belum ada setoran' / 'Belum ada murajaah' — NULL ≠ 0 applies only to absent data — cost if wrong: wording.
- Task 15: Ruling: make MemorizationFactorScoreProvider stateless (no cache) like the Attendance provider — correctness over ~2 queries — cost if wrong: two extra queries per Tahfizh recap.
- Task 16: Ruling: finalized-rapor write guard covers per-santri writes only — student_grades upsert, task score upsert, memorization log create/update/delete, memorization target create/update/delete, and attendance edits that change an EXISTING row of a finalized santri. Class-level actions (record a new Pertemuan, cancel/libur, Tugas CRUD) stay allowed; finalized santri are protected by the snapshot (ADR 0001) — keeps Task 10's "every expected santri has a status" rule workable — cost if wrong: a late class-level change is invisible on a finalized rapor (by design).
- Task 16: Ruling: after finalization both recaps read the snapshot for that santri (per-student recap returns snapshot with is_finalized=true; class recap substitutes the snapshot row for finalized santri) — spec US90 — cost if wrong: extra mapping code.
- Task 16: Ruling: accept attendance notes-only change counting as a change (rejected for finalized santri) — simplest consistent rule — cost if wrong: notes can't be fixed after final without unfinalize.
- Task 17: Ruling (reviewer ⚠️): PDF shows the live student name and the label of the snapshotted class_level_id — a name correction after finalization should appear on the printed rapor; grades/weights come from the snapshot — cost if wrong: add name to snapshot.
- Task 18: Ruling: legacy nav items in non-super_admin role blocks are REMOVED (not repointed) because new routes only allow super_admin + pengurus_pesantren — cost if wrong: those roles lose a link to pages they couldn't open anyway.
- Ruling: final fix wave scope = backend {AY delete guard, PSB class_level_id, de-flake 3 tests, stale PercentScoreValidator docblock} + frontend {StudentSearchCombobox error state, F1 copy incl. super_admin slug, F2 class label, F3 shared id-ID number formatter, F4-lite breadcrumb, 5 new tsc errors, dashboard "Rekap Absensi" label}. Everything else deferred per reviewers' triage — cost if wrong: follow-up tickets.
- Ruling: --seed permission reset is pre-existing seeder behaviour → handled as a deployment note to the user, not changed in this branch — cost if wrong: custom pengurus grants lost on seed unless recorded first.
- CODE REVIEW medium: 8 findings (1 High, 3 Medium, 4 Low) + 1 extra; Ruling: fix 1–6, 8, 9 now on the unpushed branch (user goal = complete quality implementation); #7 stays accepted per Task 16 ruling (add docblock note) — cost if wrong: none (reversible commits)

## Temuan minor yang ditunda (bisa menunggu, sudah ditriase review akhir)

- Task 1: minor (deferred): StudentService::updateStudent no-ops class_level_id resolution when student.school_id NULL (no fallback to active school)
- Task 1: minor (deferred): backfill logs unresolved student-row count, not distinct slugs
- Task 1: minor (deferred): no test for NULL-school_id + ambiguous active-school-count branch
- Task 2: minor (deferred): test helper createSchoolAndUser duplicated per file (pre-existing repo convention)
- Task 2: minor (deferred): final review should sweep other FormRequests for withValidator reads of tenant data before tenancy check (pattern risk for later tasks)
- Task 3: minor (deferred): GradingFactorController::update repeats tenancy check already done in FormRequest authorize()
- Task 3: minor (deferred): replaceSemesterWeights expected-factor list derived from existing rows (unhelpful 422 if rows missing)
- Task 3: minor (deferred): updateFactor does not sort scale_levels by level
- Task 4: minor (deferred): pre-existing — SubjectBookController show/update/destroy lack ensureBelongsToActiveSchool on route model (constraint 1 gap predating feature)
- Task 4: minor (deferred): assignDefaultTemplateToSubjectBooks gives teori_kitab to a tahfizh book if only teori_kitab template exists (unreachable today)
- Task 4: minor (deferred): no lang/id validation file; implicit rule messages fall back to English (repo-wide)
- Task 5: minor (deferred): stale draft can overwrite newer server value on restore (no savedAt vs updated_at check)
- Task 5: minor (deferred): failed background refetch unmounts grid (should show banner over cached data)
- Task 5: minor (deferred): concurrent insert of same new cell → unique violation 500
- Task 5: minor (deferred): upsert writes raw validated score string instead of normalized float
- Task 5: minor (deferred): test gaps (GET kitab without template, non-numeric/boolean score, page error state); cell errors lack aria-describedby
- Task 5: minor (deferred): AcademicYearService::deleteAcademicYear checks only schedules; grading_template_factors/student_grades restrict FKs → 500 instead of 422
- Task 5: minor (deferred): getActive() now rethrows non-404 → other useAcademicYear callers spin through 3 retries on 5xx before error
- Task 6: minor (deferred): final NULL with no missing factors shown as "Belum lengkap" without reason when nothing counts (near-unreachable)
- Task 6: minor (deferred): row is_active = "counts for this santri", header is_active = weight row status (Tasks 9/16 must not confuse)
- Task 6: minor (deferred): buildStudentRow recomputes class-constant factor lists per student
- Task 6: minor (deferred): registry doesn't assert providers return FactorScore (TypeError → 500) — carried into Tasks 8/12/15 dispatches
- Task 6: minor (deferred): "UTS off" note shown even when UTS weight row already inactive; missing_reason only in title tooltip; no page-level error-state test; header weights can mislead when all students entered after UTS
- Task 7: minor (deferred): convertLevelToScore silently returns null for a level missing from scale_levels (unreachable via API)
- Task 7: minor (deferred): GRADE_LEVEL_MIN/MAX exported but unused; level grid lacks keyboard row navigation
- Task 8: minor (deferred): GET /class-tasks returns 422 for unscheduled/unconfigured pair (consistent with grid GET)
- Task 8: minor (deferred): no update-path test for title max 150
- Task 8: minor (deferred): no tests for entry_date == task_date boundary and null entry_date in TaskExpectationRule; stale doc comment in PercentScoreValidator referencing deleted validateScore()
- Task 9: minor (deferred): duplicate formatDate helper in RekapSantriPage and ClassGradeRecapTable
- Task 10: minor (deferred): updateAttendances concurrent insert of new row → 500 (lock session row); duplicate-catch path untested; AbsensiPertemuanPage 502 lines / AttendanceGrid 440 lines; silent failure on unknown ?schedule; real-time waits in tests
- Task 10: minor (deferred): isInsideEditWindow computes Jakarta now inline instead of via businessToday() helper
- Task 11: minor (deferred): MissingSessionFinder teacherId parameter unused; "find active AY" query repeated in two services
- Task 12: minor (deferred): AttendanceRecapService re-implements the scheduled-pair + semester-configured checks (same order/messages) instead of calling a shared method
- Task 12: minor (deferred): tally uses two batched queries (brief said one) — fine
- Task 13: minor (deferred): ClassTask can be created against the Tahfizh kitab pair (inert — no tugas factor in Tahfizh template); unused frontend PAGES_PER_JUZ; TargetHafalanPage list search not debounced
- Task 14: minor (deferred): GradableSubjectService constructor placed mid-class; MemorizationFactorCalculator instantiated inline
- Task 14: minor (deferred): flaky test StudentGradeTest.php:530 — AcademicYear factory name collides with unique (school_id, name) under random names; fix factory to unique names (final review)
- Task 15: minor (deferred): verbose docblocks; duplicated mapWithKeys branches in scoresFor
- Task 16: minor (deferred): guard-vs-finalize race (write can land after snapshot computed; snapshot never changes) — add docblock sentence noting accepted race
- Task 16: minor (deferred): class recap expanded detail for snapshot rows uses live uts_enabled instead of the frozen value
- Task 16: minor (deferred): show() loads entries for draft rapor; snapshot subjects omit ungradable kitab; no test for weight/UTS change after final; unfinalize history overwritten
- Task 17: minor (deferred): ReportCardPdfPresenter::assertFinal public but only used internally; download button aria-label not updated while downloading

## Catatan deploy (dari review akhir backend)

- Backend: `deploy.sh --migrate --seed`. Tanpa `--seed` tidak ada template/faktor/bobot/permission/kitab Tahfizh, sehingga semua endpoint penilaian 422.
- `RolePermissionSeeder` memakai `syncPermissions` untuk `pengurus_pesantren`: grant kustom lewat editor role akan hilang saat `--seed`. Catat dulu permission pengurus_pesantren, atau jalankan hanya `SubjectCategorySeeder`, `GradingDefaultsSeeder`, `TahfizhSubjectBookSeeder` per kelas lalu beri 7 permission baru lewat UI.
- Sebelum seed: cek apakah produksi sudah punya kitab/fann tahfizh dengan nama lain (seeder akan membuat satu lagi).
- Setelah deploy: 2 template, 10 faktor, 60 baris bobot (6 semester), semua kitab punya template, tidak ada warning backfill `class_level_id` di log.
- Di UI: isi tanggal mulai/selesai/UTS semester aktif; sebelum itu alert Pertemuan Bolong berstatus "belum dikonfigurasi".
- Frontend dideploy di jendela yang sama (halaman Supabase lama sudah dihapus).
- Lokal: endpoint PDF (termasuk ekspor jadwal yang lama) error 500 di Herd PHP-FPM karena `node` (nvm) tidak ada di PATH proses PHP; render lewat CLI berhasil. Produksi tidak terdampak selama node ada di PATH PHP-FPM.
