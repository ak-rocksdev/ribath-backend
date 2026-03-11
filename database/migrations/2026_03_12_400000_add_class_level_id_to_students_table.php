<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignUuid('class_level_id')
                ->nullable()
                ->after('class_level')
                ->constrained('class_levels');
        });

        // Backfill class_level_id from existing class_level slug.
        // Uses a correlated subquery for SQLite + PostgreSQL compatibility.
        DB::statement("
            UPDATE students
            SET class_level_id = (
                SELECT cl.id
                FROM class_levels cl
                WHERE cl.slug = students.class_level
                  AND cl.school_id = students.school_id
                LIMIT 1
            )
            WHERE students.class_level IS NOT NULL
              AND students.class_level_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_level_id');
        });
    }
};
