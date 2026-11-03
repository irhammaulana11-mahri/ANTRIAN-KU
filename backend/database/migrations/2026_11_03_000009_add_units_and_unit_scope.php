<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah tabel units (sub-cabang) dan kolom unit_id ke tabel yang perlu cakupan unit:
 * counters, employees, display_devices, serta users (untuk Admin Unit).
 * Menambah offline_only ke queue_settings agar cabang/unit bisa menonaktifkan pendaftaran online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('unit_code', 40);
            $table->string('unit_name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'unit_code']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        // Kolom unit_id: opsional (nullable) agar data lama tetap valid.
        // Loket, pegawai, dan perangkat yang tidak terikat unit tertentu dibiarkan null.
        Schema::table('counters', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->index('unit_id');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->index('unit_id');
        });

        Schema::table('display_devices', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->index('unit_id');
        });

        // unit_id pada users dipakai untuk Admin Unit agar tahu cakupan unitnya.
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
        });

        // Antrian offline-only: nonaktifkan pendaftaran online/mandiri, hanya walk-in/kiosk.
        Schema::table('queue_settings', function (Blueprint $table): void {
            $table->boolean('offline_only')->default(false)->after('stale_heartbeat_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('queue_settings', function (Blueprint $table): void {
            $table->dropColumn('offline_only');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
        Schema::table('counters', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
        Schema::dropIfExists('units');
    }
};
