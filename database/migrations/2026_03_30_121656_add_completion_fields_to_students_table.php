<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->after('full_name');
            $table->string('email', 255)->nullable()->after('nik');
            $table->string('phone', 20)->nullable()->after('email');
            $table->smallInteger('child_order')->nullable()->after('phone');
            $table->smallInteger('siblings_count')->nullable()->after('child_order');
            $table->string('profile_completion_status', 20)->default('incomplete')->after('status');
            $table->text('motivation')->nullable()->after('notes');
            $table->string('family_income_range', 50)->nullable()->after('motivation');
            $table->timestamp('agreed_to_rules_at')->nullable()->after('profile_completed_at');
            $table->timestamp('agreed_to_commitment_at')->nullable()->after('agreed_to_rules_at');
            $table->timestamp('data_verified_at')->nullable()->after('agreed_to_commitment_at');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'nik', 'email', 'phone', 'child_order', 'siblings_count',
                'profile_completion_status', 'motivation', 'family_income_range',
                'agreed_to_rules_at', 'agreed_to_commitment_at', 'data_verified_at',
            ]);
        });
    }
};
