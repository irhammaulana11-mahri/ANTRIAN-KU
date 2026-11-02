<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('sqms:about', function (): void {
    $this->info('ANTRIAN-KU backend foundation');
})->purpose('Display ANTRIAN-KU information');
