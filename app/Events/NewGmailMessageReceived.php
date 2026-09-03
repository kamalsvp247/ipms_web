<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewGmailMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('gmail.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }
}
