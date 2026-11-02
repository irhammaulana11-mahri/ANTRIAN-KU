<?php

namespace App\Events;

class QueueStatusChanged extends BranchEvent
{
    public function broadcastAs(): string
    {
        return 'queue.status-changed';
    }
}
