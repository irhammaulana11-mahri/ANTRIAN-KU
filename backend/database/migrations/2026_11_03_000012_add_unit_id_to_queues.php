<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->uuid('unit_id')->nullable()->after('branch_id')->index();
        });

        // Isi unit antrian lama dari loket (atau petugas) yang pernah menanganinya.
        DB::table('queue_assignments')
            ->join('counters', 'counters.id', '=', 'queue_assignments.counter_id')
            ->leftJoin('employees', 'employees.id', '=', 'queue_assignments.employee_id')
            ->select('queue_assignments.queue_id', 'counters.unit_id as counter_unit', 'employees.unit_id as employee_unit')
            ->orderBy('queue_assignments.assigned_at')
            ->get()
            ->each(function ($row): void {
                $unit = $row->counter_unit ?? $row->employee_unit;
                if ($unit) {
                    DB::table('queues')->where('id', $row->queue_id)->whereNull('unit_id')->update(['unit_id' => $unit]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropIndex(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
