<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Semester akademik (ADR 0002): satu baris per pasangan
     * (academic_year_id, semester) membawa atribut kalender per semester
     * (tanggal mulai/selesai, tanggal UTS, flag UTS diadakan). Tabel-tabel
     * penilaian/absensi/hafalan tetap membawa pasangan yang sama seperti
     * jadwal — academic_semesters dicari lewat pasangan itu, bukan
     * dijadikan target FK.
     */
    public function up(): void
    {
        Schema::create('academic_semesters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->unsignedSmallInteger('semester')->comment('1 or 2');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('midterm_exam_date')->nullable();
            $table->boolean('uts_enabled')->default(true);
            $table->timestamps();

            $table->unique(['academic_year_id', 'semester']);
        });

        // PG-only CHECKs (SQLite tests skip — service/request layer enforces too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE academic_semesters ADD CONSTRAINT academic_semesters_semester_check CHECK (semester IN (1, 2))');
            DB::statement('ALTER TABLE academic_semesters ADD CONSTRAINT academic_semesters_date_order_check CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)');
        }

        $this->backfillExistingAcademicYears();
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_semesters');
    }

    /**
     * Insert semesters 1 and 2 for every academic year that doesn't
     * already have them — needed both for the deploy-time backfill of
     * pre-existing academic years and (idempotently) as a safety net if
     * ever re-run. Uses the query builder + Str::uuid7() rather than the
     * Eloquent model so this migration stays correct even if app code
     * changes later.
     */
    public function backfillExistingAcademicYears(): void
    {
        $now = now();

        $academicYears = DB::table('academic_years')->select('id', 'school_id')->get();

        foreach ($academicYears as $academicYear) {
            $existingSemesters = DB::table('academic_semesters')
                ->where('academic_year_id', $academicYear->id)
                ->pluck('semester')
                ->all();

            foreach ([1, 2] as $semesterNumber) {
                if (in_array($semesterNumber, $existingSemesters, true)) {
                    continue;
                }

                DB::table('academic_semesters')->insert([
                    'id' => (string) Str::uuid7(),
                    'school_id' => $academicYear->school_id,
                    'academic_year_id' => $academicYear->id,
                    'semester' => $semesterNumber,
                    'start_date' => null,
                    'end_date' => null,
                    'midterm_exam_date' => null,
                    'uts_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
