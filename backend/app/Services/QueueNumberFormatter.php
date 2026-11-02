<?php

namespace App\Services;

class QueueNumberFormatter
{
    public function format(string $prefix, int $sequence): string
    {
        return $prefix.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
