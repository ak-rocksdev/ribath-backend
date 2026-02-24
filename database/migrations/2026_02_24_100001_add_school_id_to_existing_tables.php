<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
        });

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->foreignUuid('school_id')->nullable()->after('id')->constrained('schools')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
