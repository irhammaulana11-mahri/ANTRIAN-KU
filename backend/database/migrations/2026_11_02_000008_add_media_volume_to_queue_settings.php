<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_settings', 'media_volume')) {
            Schema::table('queue_settings', function (Blueprint $table): void {
                $table->decimal('media_volume', 4, 2)->default(0.35)->after('voice_volume');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('queue_settings', 'media_volume')) {
            Schema::table('queue_settings', function (Blueprint $table): void {
                $table->dropColumn('media_volume');
            });
        }
    }
};
