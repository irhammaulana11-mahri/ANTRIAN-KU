<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;

trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $this->guardAgainstRealDatabase($app);

        return $app;
    }

    /**
     * Test memakai RefreshDatabase/DatabaseTruncation yang menghapus data. Jika koneksi PostgreSQL dipakai,
     * wajib schema khusus test (nama berakhiran _test) agar data aplikasi di schema "public" tidak pernah tersentuh.
     */
    private function guardAgainstRealDatabase($app): void
    {
        $default = $app['config']['database.default'];
        $config = $app['config']["database.connections.{$default}"];
        if ($default === 'pgsql' && ! str_ends_with((string) ($config['search_path'] ?? 'public'), '_test')) {
            throw new RuntimeException('Test PostgreSQL ditolak: DB_SCHEMA harus berakhiran "_test" (mis. sqms_test), bukan "'.($config['search_path'] ?? 'public').'". Gunakan phpunit.pgsql.xml.');
        }
        if ($default !== 'pgsql' && ($config['database'] ?? '') !== ':memory:') {
            throw new RuntimeException('Test hanya boleh memakai SQLite :memory: atau schema PostgreSQL *_test.');
        }
    }
}
