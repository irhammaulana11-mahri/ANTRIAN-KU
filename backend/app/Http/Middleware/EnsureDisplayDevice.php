<?php

namespace App\Http\Middleware;

use App\Models\DisplayDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya perangkat aktif dengan tipe yang diizinkan DAN token ber-ability yang sesuai yang boleh lewat.
 * Pemakaian: `device.type:DISPLAY`, `device.type:KIOSK`, atau `device.type:DISPLAY,KIOSK`.
 * Token TV tidak dapat dipakai di endpoint kiosk, dan sebaliknya.
 */
class EnsureDisplayDevice
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $types = $types ?: [DisplayDevice::DISPLAY];
        $device = $request->user();
        if (! $device instanceof DisplayDevice || $device->isRevoked() || ! in_array($device->device_type, $types, true) || ! $device->tokenCan($device->ability())) {
            return response()->json(['message' => 'Perangkat tidak berwenang untuk endpoint ini.'], 403);
        }
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}
