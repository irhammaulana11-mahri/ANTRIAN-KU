<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('display_devices', function (Blueprint $table): void {
            // DISPLAY = TV Central Display (baca-saja); KIOSK = tablet pengambilan nomor walk-in.
            $table->string('device_type', 20)->default('DISPLAY');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE display_devices ADD CONSTRAINT display_devices_type_check CHECK (device_type IN ('DISPLAY','KIOSK'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE display_devices DROP CONSTRAINT IF EXISTS display_devices_type_check');
        }
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->dropColumn('device_type');
        });
    }
};
