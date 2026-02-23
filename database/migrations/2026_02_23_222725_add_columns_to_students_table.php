<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('class_level', 30)->nullable()->after('program');
            $table->text('address')->nullable()->after('class_level');
            $table->string('photo_url')->nullable()->after('address');
            $table->text('notes')->nullable()->after('photo_url');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['class_level', 'address', 'photo_url', 'notes']);
            $table->dropSoftDeletes();
        });
    }
};
