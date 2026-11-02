<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Concurrency;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Menjalankan beberapa closure di proses PHP terpisah (koneksi database masing-masing) dan menahan
 * semuanya di barrier waktu yang sama, sehingga benar-benar berebut — bukan berjalan berurutan.
 */
final class Parallel
{
    /**
     * @param  list<Closure>  $tasks  closure statis; tidak boleh menangkap $this
     * @return list<array{ok: bool, value?: mixed, error?: string, status?: int|null}>
     */
    public static function run(array $tasks, float $lead = 3.0): array
    {
        $go = microtime(true) + $lead;
        $wrapped = [];
        foreach (array_values($tasks) as $task) {
            $wrapped[] = self::wrap($task, $go);
        }

        return array_values(Concurrency::driver('process')->run($wrapped));
    }

    /**
     * Closure pembungkus harus berada di barisnya sendiri: serializable-closure mencocokkan closure
     * berdasarkan nomor baris, sehingga dua closure pada satu baris akan tertukar.
     */
    private static function wrap(Closure $task, float $go): Closure
    {
        return static function () use ($task, $go): array {
            while (microtime(true) < $go) {
                usleep(200);
            }
            try {
                return ['ok' => true, 'value' => $task()];
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => $e::class.': '.$e->getMessage(), 'status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null];
            }
        };
    }
}
