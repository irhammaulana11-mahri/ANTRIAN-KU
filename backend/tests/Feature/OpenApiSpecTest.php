<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Menjaga dokumentasi OpenAPI tetap sinkron dengan rute dan izin yang sebenarnya. */
class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    private function spec(): array
    {
        return Yaml::parseFile(resource_path('openapi.yaml'));
    }

    /** @return list<string> "METHOD /path" untuk seluruh operasi di dokumen */
    private function documented(array $spec): array
    {
        $operations = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach (array_intersect(array_keys($item), self::METHODS) as $method) {
                $prefix = ($item[$method]['servers'][0]['url'] ?? null) === '/' ? '' : '/api/v1';
                $operations[] = strtoupper($method).' '.$prefix.$path;
            }
        }

        return $operations;
    }

    /** @return list<string> */
    private function registered(): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();
            if (! str_starts_with($uri, '/api/v1/') && $uri !== '/broadcasting/auth') {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || ($uri === '/broadcasting/auth' && $method === 'GET')) {
                    continue; // GET /broadcasting/auth hanyalah alias POST
                }
                $operations[] = $method.' '.$uri;
            }
        }

        return $operations;
    }

    public function test_every_registered_api_route_is_documented_and_nothing_stale_remains(): void
    {
        $spec = $this->spec();
        $documented = $this->documented($spec);
        $registered = $this->registered();

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'Rute belum didokumentasikan di resources/openapi.yaml');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'Dokumentasi memuat endpoint yang tidak ada lagi');
        $this->assertGreaterThan(60, count($registered));
    }

    public function test_document_is_structurally_sound(): void
    {
        $spec = $this->spec();
        $this->assertStringStartsWith('3.0', $spec['openapi']);
        $ids = [];
        foreach ($spec['paths'] as $path => $item) {
            preg_match_all('/\{([a-z_]+)\}/', $path, $matches);
            foreach (array_intersect(array_keys($item), self::METHODS) as $method) {
                $operation = $item[$method];
                $where = strtoupper($method).' '.$path;
                $this->assertNotEmpty($operation['operationId'] ?? null, "$where tanpa operationId");
                $this->assertNotContains($operation['operationId'], $ids, "operationId ganda: {$operation['operationId']}");
                $ids[] = $operation['operationId'];
                $this->assertNotEmpty($operation['tags'] ?? [], "$where tanpa tag");
                $this->assertNotEmpty($operation['summary'] ?? '', "$where tanpa ringkasan");
                $this->assertNotEmpty($operation['responses'] ?? [], "$where tanpa respons");
                $declared = collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all();
                foreach ($matches[1] as $name) {
                    $this->assertContains($name, $declared, "$where: parameter path {{$name}} belum dideklarasikan");
                }
                if (in_array($method, ['post', 'put', 'patch'], true) && ! str_contains($path, 'restore') && ! str_contains($path, 'revoke') && ! str_contains($path, 'rotate') && ! str_contains($path, 'logout') && ! str_contains($path, 'heartbeat') && ! str_contains($path, 'refresh') && ! str_contains($path, '/cancel')) {
                    $this->assertArrayHasKey('requestBody', $operation, "$where seharusnya mendokumentasikan requestBody");
                }
            }
        }
    }

    public function test_all_references_resolve(): void
    {
        $spec = $this->spec();
        $refs = [];
        array_walk_recursive($spec, function ($value, $key) use (&$refs): void {
            if ($key === '$ref') {
                $refs[] = $value;
            }
        });
        $this->assertNotEmpty($refs);
        foreach (array_unique($refs) as $ref) {
            $this->assertStringStartsWith('#/', $ref);
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $node, "referensi tidak ditemukan: $ref");
                $node = $node[$segment];
            }
        }
    }

    public function test_documented_permissions_exist(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $known = Permission::pluck('name')->all();
        $found = 0;
        foreach ($this->spec()['paths'] as $item) {
            foreach (array_intersect_key($item, array_flip(self::METHODS)) as $operation) {
                if (preg_match('/\*\*Izin:\*\*(.+?)\.?$/m', $operation['description'] ?? '', $line)) {
                    preg_match_all('/`([a-z]+\.[a-z]+)`/', $line[1], $names);
                    foreach ($names[1] as $name) {
                        $found++;
                        $this->assertContains($name, $known, "izin `$name` pada dokumentasi tidak ada di RolePermissionSeeder");
                    }
                }
            }
        }
        $this->assertGreaterThan(30, $found);
    }

    public function test_docs_endpoints_serve_the_spec(): void
    {
        config(['app.api_docs' => true]);
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui', false)->assertSee('/api/docs/openapi.yaml', false);
        $served = $this->get('/api/docs/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8')->getContent();
        $this->assertSame('SQMS — Smart Queue Management System API', Yaml::parse($served)['info']['title']);

        config(['app.api_docs' => false]);
        $this->get('/api/docs')->assertNotFound();
        $this->get('/api/docs/openapi.yaml')->assertNotFound();
    }
}
