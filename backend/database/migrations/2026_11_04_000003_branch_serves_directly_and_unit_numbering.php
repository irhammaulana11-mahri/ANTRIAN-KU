<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - queue_settings.branch_serves_directly: cabang membuka layanan sendiri (antrian tanpa unit). Default true
 *   agar cabang yang sudah berjalan tanpa unit tidak berubah perilaku.
 * - Nomor urut per titik layanan: queue_sequences mendapat unit_id, dan keunikan nomor di tabel queues
 *   berlaku per (cabang, layanan, tanggal, unit atau cabang). unit_id NULL = antrian milik cabang.
 */
return new class extends Migration
{
    private const NIL = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        Schema::table('queue_settings', function (Blueprint $table): void {
            $table->boolean('branch_serves_directly')->default(true);
        });

        Schema::table('queue_sequences', function (Blueprint $table): void {
            $table->uuid('unit_id')->nullable()->after('branch_id');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->dropUnique(['branch_id', 'service_type_id', 'queue_date']);
        });
        DB::statement('CREATE UNIQUE INDEX queue_sequences_scope_unique ON queue_sequences (branch_id, service_type_id, queue_date, COALESCE(unit_id, \''.self::NIL.'\'::uuid))');

        Schema::table('queues', function (Blueprint $table): void {
            $table->dropUnique(['branch_id', 'service_type_id', 'queue_date', 'sequence_no']);
        });
        DB::statement('CREATE UNIQUE INDEX queues_number_scope_unique ON queues (branch_id, service_type_id, queue_date, sequence_no, COALESCE(unit_id, \''.self::NIL.'\'::uuid))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX queues_number_scope_unique');
        Schema::table('queues', function (Blueprint $table): void {
            $table->unique(['branch_id', 'service_type_id', 'queue_date', 'sequence_no']);
        });
        DB::statement('DROP INDEX queue_sequences_scope_unique');
        Schema::table('queue_sequences', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
            $table->unique(['branch_id', 'service_type_id', 'queue_date']);
        });
        Schema::table('queue_settings', function (Blueprint $table): void {
            $table->dropColumn('branch_serves_directly');
        });
    }
};
