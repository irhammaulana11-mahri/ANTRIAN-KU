<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

abstract class BranchEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    public string $occurredAt;

    public function __construct(public string $branchId, public array $payload = [], public ?string $employeeId = null)
    {
        $this->eventId = (string) Str::orderedUuid();
        $this->occurredAt = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('branch.'.$this->branchId)];
        // Event milik unit juga dikirim ke channel unit, dipakai perangkat display/kiosk yang terkunci ke unit.
        if (! empty($this->payload['unit_id'])) {
            $channels[] = new PrivateChannel('branch.'.$this->branchId.'.unit.'.$this->payload['unit_id']);
        }
        if ($this->employeeId) {
            $channels[] = new PrivateChannel('employee.'.$this->employeeId);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return array_merge(['event_id' => $this->eventId, 'occurred_at' => $this->occurredAt, 'branch_id' => $this->branchId], $this->payload);
    }
}
