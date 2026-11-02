<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('user_id')->nullable()->unique();
            $table->string('employee_code', 40);
            $table->string('employee_name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'employee_code']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('service_code', 40);
            $table->string('service_name', 150);
            $table->string('queue_prefix', 10);
            $table->boolean('priority_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'service_code']);
            $table->unique(['branch_id', 'queue_prefix']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('counter_code', 40);
            $table->string('counter_name', 150);
            $table->string('counter_type', 30);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'counter_code']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('employee_services', function (Blueprint $table): void {
            $table->uuid('employee_id');
            $table->uuid('service_type_id');
            $table->timestampsTz();
            $table->primary(['employee_id', 'service_type_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('service_type_id')->references('id')->on('service_types')->cascadeOnDelete();
        });

        Schema::create('employee_counters', function (Blueprint $table): void {
            $table->uuid('employee_id');
            $table->uuid('counter_id');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->primary(['employee_id', 'counter_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('counter_id')->references('id')->on('counters')->cascadeOnDelete();
        });

        Schema::create('priority_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('priority_code', 30);
            $table->string('priority_name', 100);
            $table->integer('weight')->default(0);
            $table->integer('aging_rate')->default(1);
            $table->integer('aging_interval_seconds')->default(300);
            $table->boolean('verification_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['branch_id', 'priority_code']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('operating_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->smallInteger('weekday');
            $table->timeTz('opens_at');
            $table->timeTz('closes_at');
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz();
            $table->unique(['branch_id', 'weekday']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->date('holiday_date');
            $table->string('holiday_name', 150);
            $table->timestampsTz();
            $table->unique(['branch_id', 'holiday_date']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('operating_hours');
        Schema::dropIfExists('priority_types');
        Schema::dropIfExists('employee_counters');
        Schema::dropIfExists('employee_services');
        Schema::dropIfExists('counters');
        Schema::dropIfExists('service_types');
        Schema::dropIfExists('employees');
    }
};
