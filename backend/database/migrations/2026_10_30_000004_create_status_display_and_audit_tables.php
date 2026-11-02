<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_status_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('employee_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('recorded_at');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['employee_id', 'branch_id'])->references(['id', 'branch_id'])->on('employees')->restrictOnDelete();
            $table->index(['branch_id', 'employee_id', 'recorded_at']);
        });

        Schema::create('display_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('device_code', 80);
            $table->string('device_name', 150);
            $table->string('credential_hash', 255);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'device_code']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'revoked_at']);
        });

        Schema::create('queue_call_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('queue_id');
            $table->uuid('employee_id');
            $table->uuid('counter_id');
            $table->string('call_type', 20);
            $table->uuid('event_id')->unique();
            $table->timestampTz('called_at');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['queue_id', 'branch_id'])->references(['id', 'branch_id'])->on('queues')->restrictOnDelete();
            $table->foreign(['employee_id', 'branch_id'])->references(['id', 'branch_id'])->on('employees')->restrictOnDelete();
            $table->foreign(['counter_id', 'branch_id'])->references(['id', 'branch_id'])->on('counters')->restrictOnDelete();
            $table->index(['branch_id', 'called_at']);
        });

        Schema::create('queue_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('queue_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->uuid('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('transitioned_at');
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign(['queue_id', 'branch_id'])->references(['id', 'branch_id'])->on('queues')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'queue_id', 'transitioned_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 150);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestampTz('created_at');
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->string('event_type', 150);
            $table->string('aggregate_type', 150);
            $table->uuid('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('published_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index(['published_at', 'created_at']);
            $table->index(['branch_id', 'event_type']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->string('key', 120);
            $table->string('request_hash', 128);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['actor_id', 'key']);
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->index('expires_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE employee_status_logs ADD CONSTRAINT employee_status_logs_status_check CHECK (to_status IN ('AVAILABLE','BUSY','BREAK','OFFLINE','CLOSED'))");
            DB::statement("ALTER TABLE queue_call_logs ADD CONSTRAINT queue_call_logs_type_check CHECK (call_type IN ('INITIAL','RECALL'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('queue_transitions');
        Schema::dropIfExists('queue_call_logs');
        Schema::dropIfExists('display_devices');
        Schema::dropIfExists('employee_status_logs');
    }
};
