<?php

namespace Database\Factories;

use App\Models\CaptchaNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CaptchaNode>
 */
class CaptchaNodeFactory extends Factory
{
    protected $model = CaptchaNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'node-'.Str::random(6),
            'api_key' => CaptchaNode::generateApiKey(),
            'enabled' => true,
            'profile' => 'dedicated',
            'status' => 'online',
            'worker_state' => 'idle',
            'last_heartbeat_at' => now(),
            'ip_address' => '10.0.0.1',
            'hostname' => 'solver-'.Str::random(4),
            'cpu_cores' => 8,
            'reported_concurrency' => 8,
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => 'offline',
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['worker_state' => 'paused']);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
