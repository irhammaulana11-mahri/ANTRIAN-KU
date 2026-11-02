<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_settings', function (Blueprint $table): void {
            // Daftar sumber video TV berurutan (IPTV/stream atau YouTube); display memakai yang pertama tersambung.
            $table->json('display_media')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('queue_settings', function (Blueprint $table): void {
            $table->dropColumn('display_media');
        });
    }
};
