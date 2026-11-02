<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=()');
        if ($csp = $this->contentSecurityPolicy($request, $response)) {
            $response->headers->set('Content-Security-Policy', $csp);
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * CSP untuk respons yang disajikan Laravel. SPA React disajikan Nginx dan memakai CSP-nya sendiri (deploy/nginx/sqms.conf).
     * - Halaman Swagger UI: izinkan skrip dari CDN + satu skrip inline ber-nonce.
     * - API/JSON: tidak ada sumber yang diizinkan sama sekali.
     * - Halaman HTML lain saat APP_DEBUG: dilewati agar halaman error (Ignition) tetap tampil.
     */
    private function contentSecurityPolicy(Request $request, Response $response): ?string
    {
        if ($nonce = $request->attributes->get('csp_nonce')) {
            return "default-src 'none'; script-src 'self' https://unpkg.com 'nonce-{$nonce}'; style-src 'self' https://unpkg.com 'unsafe-inline'; img-src 'self' data: https:; font-src https://unpkg.com data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        }
        $isJson = str_contains((string) $response->headers->get('Content-Type'), 'json');
        if ($isJson || ! config('app.debug')) {
            return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        }

        return null;
    }
}
