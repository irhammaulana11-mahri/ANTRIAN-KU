<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Menyajikan dokumentasi OpenAPI (Swagger UI). Dinonaktifkan di produksi kecuali API_DOCS_ENABLED=true. */
class ApiDocsController extends Controller
{
    public function ui(Request $request): Response
    {
        abort_unless(config('app.api_docs'), 404);
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce); // dibaca SecurityHeaders untuk menyusun CSP halaman ini
        $spec = e(url('/api/docs/openapi.yaml'));

        return response(<<<HTML
        <!doctype html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>SQMS API — Dokumentasi</title>
            <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
            <script nonce="{$nonce}">
                window.ui = SwaggerUIBundle({ url: "{$spec}", dom_id: "#swagger-ui", persistAuthorization: true, tryItOutEnabled: false, docExpansion: "list", deepLinking: true });
            </script>
        </body>
        </html>
        HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function spec(): Response
    {
        abort_unless(config('app.api_docs'), 404);

        return response(file_get_contents(resource_path('openapi.yaml')), 200, ['Content-Type' => 'application/yaml; charset=UTF-8']);
    }
}
