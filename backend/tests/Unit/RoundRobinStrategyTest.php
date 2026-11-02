<?php

namespace Tests\Unit;

use App\Models\DistributionCursor;
use App\Models\Employee;
use App\Services\RoundRobinStrategy;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class RoundRobinStrategyTest extends TestCase
{
    public function test_missing_cursor_employee_starts_from_first_eligible_employee(): void
    {
        $first = new Employee(['id' => 'employee-a']);
        $second = new Employee(['id' => 'employee-b']);
        $cursor = new DistributionCursor(['last_employee_id' => 'employee-on-break']);

        $selected = (new RoundRobinStrategy)->chooseEmployee(new Collection([$first, $second]), $cursor);

        $this->assertSame('employee-a', $selected->id);
    }
}
