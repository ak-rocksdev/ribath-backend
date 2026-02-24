<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('profile_completed_at')->nullable()->after('notes');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('rejection_reason');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('profile_completed_at');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex(['is_archived']);
            $table->dropColumn('is_archived');
        });
    }
};
