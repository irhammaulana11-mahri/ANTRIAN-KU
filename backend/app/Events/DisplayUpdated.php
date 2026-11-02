<?php

namespace App\Events;

class DisplayUpdated extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'display.updated';
    }
}
