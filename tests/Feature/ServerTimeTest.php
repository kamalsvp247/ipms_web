<?php

namespace Tests\Feature;

use Tests\TestCase;

class ServerTimeTest extends TestCase
{
    public function test_server_time_endpoint_returns_timestamp(): void
    {
        $response = $this->getJson('/api/server-time');

        $response->assertSuccessful()
            ->assertJsonStructure(['serverTimeMs']);

        $data = $response->json();
        $serverTimeMs = $data['serverTimeMs'];

        // Verify it's a reasonable timestamp (within 1 minute of now)
        $nowMs = (int) (microtime(true) * 1000);
        $this->assertIsInt($serverTimeMs);
        $this->assertLessThanOrEqual($nowMs + 1000, $serverTimeMs);
        $this->assertGreaterThanOrEqual($nowMs - 60000, $serverTimeMs);
    }
}
