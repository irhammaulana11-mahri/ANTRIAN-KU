<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kunci aktivasi hanya disimpan sebagai hash (credential_hash); perangkat dicari lewat hash tersebut.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->dropUnique(['activation_code']);
            $table->dropColumn('activation_code');
            $table->index('credential_hash');
        });
    }

    public function down(): void
    {
        Schema::table('display_devices', function (Blueprint $table): void {
            $table->dropIndex(['credential_hash']);
            $table->string('activation_code', 8)->nullable()->unique()->after('credential_hash');
        });
    }
};
