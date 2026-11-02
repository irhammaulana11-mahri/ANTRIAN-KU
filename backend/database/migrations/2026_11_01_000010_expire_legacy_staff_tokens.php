<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Token staf yang dibuat sebelum ada masa berlaku (expires_at NULL) diberi batas: created_at + staff_token_minutes.
     * Token yang sudah lebih tua dari itu langsung tidak berlaku; pengguna cukup login ulang.
     */
    public function up(): void
    {
        $ttl = (int) config('sanctum.staff_token_minutes', 720);
        DB::table('personal_access_tokens')->whereNull('expires_at')->where('tokenable_type', User::class)->orderBy('id')->each(function ($token) use ($ttl): void {
            DB::table('personal_access_tokens')->where('id', $token->id)->update(['expires_at' => Carbon::parse($token->created_at)->addMinutes($ttl)]);
        });
    }

    public function down(): void
    {
        // Tidak dapat dikembalikan secara bermakna (masa berlaku lama = tanpa batas).
    }
};
