<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->unique();
            $table->integer('online_daily_quota')->nullable();
            $table->integer('max_waiting_threshold')->nullable();
            $table->integer('normal_fairness_quota')->default(3);
            $table->integer('stale_heartbeat_seconds')->default(90);
            $table->decimal('voice_rate', 4, 2)->default(1.00);
            $table->decimal('voice_volume', 4, 2)->default(1.00);
            $table->integer('version')->default(1);
            $table->timestampsTz();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('queue_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('service_type_id');
            $table->date('queue_date');
            $table->integer('last_number')->default(0);
            $table->timestampsTz();
            $table->unique(['branch_id', 'service_type_id', 'queue_date']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign(['service_type_id', 'branch_id'])->references(['id', 'branch_id'])->on('service_types')->restrictOnDelete();
            $table->index(['branch_id', 'queue_date']);
        });

        Schema::create('queues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('service_type_id');
            $table->uuid('priority_type_id')->nullable();
            $table->date('queue_date');
            $table->integer('sequence_no');
            $table->string('queue_number', 30);
            $table->string('customer_name', 150);
            $table->string('customer_phone', 40)->nullable();
            $table->text('service_notes')->nullable();
            $table->string('registration_channel', 20);
            $table->string('status', 20)->default('WAITING');
            $table->string('token_hash', 255)->nullable()->unique();
            $table->uuid('priority_verified_by')->nullable();
            $table->timestampTz('priority_verified_at')->nullable();
            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['branch_id', 'service_type_id', 'queue_date', 'sequence_no']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['service_type_id', 'branch_id'])->references(['id', 'branch_id'])->on('service_types')->restrictOnDelete();
            $table->foreign(['priority_type_id', 'branch_id'])->references(['id', 'branch_id'])->on('priority_types')->nullOnDelete();
            $table->foreign('priority_verified_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'queue_date', 'status']);
            $table->index(['branch_id', 'service_type_id', 'status', 'created_at']);
        });

        Schema::create('queue_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('queue_id');
            $table->uuid('employee_id');
            $table->uuid('counter_id');
            $table->string('assignment_type', 20)->default('AUTO');
            $table->text('reason')->nullable();
            $table->timestampTz('assigned_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['queue_id', 'branch_id'])->references(['id', 'branch_id'])->on('queues')->restrictOnDelete();
            $table->foreign(['employee_id', 'branch_id'])->references(['id', 'branch_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['counter_id', 'branch_id'])->references(['id', 'branch_id'])->on('counters')->restrictOnDelete();
            $table->index(['branch_id', 'assigned_at']);
        });

        Schema::create('service_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('queue_id');
            $table->uuid('employee_id');
            $table->uuid('counter_id');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->string('outcome', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['queue_id', 'branch_id'])->references(['id', 'branch_id'])->on('queues')->restrictOnDelete();
            $table->foreign(['employee_id', 'branch_id'])->references(['id', 'branch_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['counter_id', 'branch_id'])->references(['id', 'branch_id'])->on('counters')->restrictOnDelete();
            $table->index(['branch_id', 'started_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE queues ADD CONSTRAINT queues_status_check CHECK (status IN ('WAITING','ASSIGNED','CALLED','SERVING','COMPLETED','SKIPPED','CANCELLED','NO_SHOW'))");
            DB::statement("ALTER TABLE queues ADD CONSTRAINT queues_registration_channel_check CHECK (registration_channel IN ('WALK_IN','ONLINE'))");
            DB::statement("ALTER TABLE queue_assignments ADD CONSTRAINT queue_assignments_type_check CHECK (assignment_type IN ('AUTO','MANUAL','TRANSFER'))");
        }
        DB::statement('CREATE UNIQUE INDEX queue_assignments_active_queue_unique ON queue_assignments (queue_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX queue_assignments_active_employee_unique ON queue_assignments (employee_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX queue_assignments_active_counter_unique ON queue_assignments (counter_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_sessions_active_queue_unique ON service_sessions (queue_id) WHERE completed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_sessions_active_employee_unique ON service_sessions (employee_id) WHERE completed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX service_sessions_active_counter_unique ON service_sessions (counter_id) WHERE completed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS service_sessions_active_counter_unique');
        DB::statement('DROP INDEX IF EXISTS service_sessions_active_employee_unique');
        DB::statement('DROP INDEX IF EXISTS service_sessions_active_queue_unique');
        DB::statement('DROP INDEX IF EXISTS queue_assignments_active_counter_unique');
        DB::statement('DROP INDEX IF EXISTS queue_assignments_active_employee_unique');
        DB::statement('DROP INDEX IF EXISTS queue_assignments_active_queue_unique');
        Schema::dropIfExists('service_sessions');
        Schema::dropIfExists('queue_assignments');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('queue_sequences');
        Schema::dropIfExists('queue_settings');
    }
};
