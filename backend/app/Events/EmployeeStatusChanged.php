<?php

namespace App\Events;

class EmployeeStatusChanged extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'employee.status-changed';
    }
}
