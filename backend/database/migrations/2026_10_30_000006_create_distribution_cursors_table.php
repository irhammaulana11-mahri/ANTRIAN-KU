<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_cursors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('service_type_id');
            $table->uuid('last_employee_id')->nullable();
            $table->timestampsTz();
            $table->unique(['branch_id', 'service_type_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign(['service_type_id', 'branch_id'])->references(['id', 'branch_id'])->on('service_types')->cascadeOnDelete();
            $table->foreign(['last_employee_id', 'branch_id'])->references(['id', 'branch_id'])->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_cursors');
    }
};
