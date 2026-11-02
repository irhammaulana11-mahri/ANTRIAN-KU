<?php

namespace Tests\Unit;

use App\Services\QueueNumberFormatter;
use PHPUnit\Framework\TestCase;

class QueueNumberFormatterTest extends TestCase
{
    public function test_it_formats_numbers_with_a_three_digit_sequence(): void
    {
        $formatter = new QueueNumberFormatter;
        $this->assertSame('CS-001', $formatter->format('CS', 1));
        $this->assertSame('TL-120', $formatter->format('TL', 120));
        $this->assertSame('CS-1000', $formatter->format('CS', 1000));
    }
}
