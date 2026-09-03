<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts synchronously (no default queue worker runs on this deployment —
 * see ShouldBroadcast vs ShouldBroadcastNow before switching this back).
 */
class PaymentLinkCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ?string $accountPhone,
        public readonly ?string $gatewayPageUrl,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('payment-links'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'link.created';
    }
}
