<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_configuration_screen_can_be_rendered_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/system-configuration');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Index')
            );
    }

    public function test_system_configuration_screen_cannot_be_rendered_by_guest(): void
    {
        $response = $this->get('/system-configuration');

        $response->assertRedirect('/login'); // Or wherever unauth goes
    }
}
