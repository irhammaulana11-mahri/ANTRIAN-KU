<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kode perangkat hanya harus unik di antara perangkat yang belum dihapus, agar kode bekas dapat dipakai lagi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'device_code']);
        });
        DB::statement('CREATE UNIQUE INDEX display_devices_branch_code_active_unique ON display_devices (branch_id, device_code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX display_devices_branch_code_active_unique');
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->unique(['branch_id', 'device_code']);
        });
    }
};
