<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE users SET unit_id = e.unit_id FROM employees e WHERE e.user_id = users.id AND e.unit_id IS NOT NULL AND users.unit_id IS DISTINCT FROM e.unit_id');
    }

    public function down(): void {}
};
