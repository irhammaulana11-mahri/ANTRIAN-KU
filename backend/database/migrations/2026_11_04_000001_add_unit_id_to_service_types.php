<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layanan dapat dibatasi ke satu unit. unit_id NULL = layanan berlaku untuk semua unit di cabang,
 * sehingga data lama tetap valid dan tidak berubah perilaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
